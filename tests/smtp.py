"""Transporte SMTP real em loopback, com STARTTLS, TLS implícito e certificados de teste."""
import argparse
from contextlib import ExitStack
import json
import os
from pathlib import Path
import secrets
import socketserver
import ssl
import subprocess
import tempfile
import threading


ROOT = Path(__file__).resolve().parents[1]


class LocalSMTP(socketserver.BaseRequestHandler):
    def handle(self):
        connection = self.request
        encrypted = False
        reader = None
        try:
            if self.server.implicit:
                connection = self.server.tls.wrap_socket(connection, server_side=True)
                encrypted = True
            connection.settimeout(10)
            reader = connection.makefile('rb')
            connection.sendall(b'220 localhost SMTP test\r\n')
            while line := reader.readline():
                command = line.split(maxsplit=1)[0].upper()
                if command == b'EHLO':
                    connection.sendall(b'250-localhost\r\n250 STARTTLS\r\n')
                elif command == b'STARTTLS':
                    connection.sendall(b'220 Begin TLS\r\n')
                    reader.close()
                    reader = None
                    connection = self.server.tls.wrap_socket(connection, server_side=True)
                    encrypted = True
                    reader = connection.makefile('rb')
                elif command == b'DATA':
                    connection.sendall(b'354 Send message\r\n')
                    lines = []
                    while data := reader.readline():
                        if data == b'.\r\n':
                            break
                        lines.append(data)
                    self.server.messages.append((encrypted, b''.join(lines)))
                    connection.sendall(b'250 Accepted\r\n')
                elif command == b'QUIT':
                    connection.sendall(b'221 Goodbye\r\n')
                    return
                else:
                    connection.sendall(b'250 OK\r\n')
        except (ssl.SSLError, ConnectionError, TimeoutError):
            # A rejeição do certificado pelo cliente é um resultado esperado.
            pass
        finally:
            if reader is not None:
                reader.close()
            connection.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--openssl', default='openssl')
    args = parser.parse_args()
    if not (ROOT / 'vendor/autoload.php').is_file():
        parser.error('Execute composer install na raiz do projeto.')
    with ExitStack() as stack:
        temp = Path(stack.enter_context(tempfile.TemporaryDirectory(prefix='hx-smtp-tls-')))
        ca = temp / 'ca.pem'
        ca_key = temp / 'ca.key'
        certificate = temp / 'server.pem'
        key = temp / 'server.key'
        request = temp / 'server.csr'
        extensions = temp / 'server.ext'
        config = temp / 'openssl.cnf'
        config.write_text('[req]\ndistinguished_name=subject\n[subject]\n', encoding='ascii')
        extensions.write_text('subjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n', encoding='ascii')
        commands = [
            ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=Hydroponex test CA',
             '-addext', 'basicConstraints=critical,CA:TRUE', '-keyout', str(ca_key), '-out', str(ca)],
            ['req', '-new', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=localhost',
             '-keyout', str(key), '-out', str(request)],
            ['x509', '-req', '-days', '1', '-in', str(request), '-CA', str(ca), '-CAkey', str(ca_key),
             '-CAcreateserial', '-extfile', str(extensions), '-out', str(certificate)],
        ]
        for command in commands:
            options = ['-config', str(config)] if command[0] == 'req' else []
            result = subprocess.run([args.openssl, *command, *options], capture_output=True)
            if result.returncode:
                raise RuntimeError('Falha na criação do certificado temporário. Confira o executável OpenSSL.')
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        context.load_cert_chain(certificate, key)
        token = secrets.token_hex(32)
        env = dict(os.environ, HYDROPONEX_MAIL_AUTOLOAD=str(ROOT / 'vendor/autoload.php'),
                   HYDROPONEX_SMTP_HOST='127.0.0.1', HYDROPONEX_SMTP_USER='', HYDROPONEX_SMTP_PASSWORD='',
                   HYDROPONEX_SMTP_FROM='convites@example.test', HYDROPONEX_PUBLIC_URL='https://hydroponex.example.test')
        bridge = """require 'public/php/services/mail.php';
        $token=stream_get_contents(STDIN);
        echo json_encode(send_team_invitation_mail('agricultor@example.test',$token));"""

        def deliver(mode, trust):
            server = stack.enter_context(socketserver.ThreadingTCPServer(('127.0.0.1', 0), LocalSMTP))
            server.daemon_threads = True
            server.implicit = mode == 'smtps'
            server.tls = context
            server.messages = []
            threading.Thread(target=server.serve_forever, daemon=True).start()
            stack.callback(server.shutdown)
            env['HYDROPONEX_SMTP_PORT'] = str(server.server_address[1])
            env['HYDROPONEX_SMTP_SECURITY'] = mode
            command = [args.php, '-d', 'display_errors=0', '-d', f'openssl.cafile={trust}', '-r', bridge]
            result = subprocess.run(command, cwd=ROOT, env=env, input=token.encode(), capture_output=True, timeout=20)
            assert result.returncode == 0, 'O transporte deve devolver uma falha controlada.'
            assert token.encode() not in result.stdout + result.stderr, 'Token exposto pelo transporte.'
            return json.loads(result.stdout), server.messages

        checks = 0
        for mode in ('tls', 'smtps'):
            sent, messages = deliver(mode, ca)
            assert sent is True and len(messages) == 1 and messages[0][0], f'{mode} deve enviar com TLS.'
            checks += 1
            print(f'OK: {mode} entrega a mensagem em conexão criptografada.', flush=True)
            sent, messages = deliver(mode, certificate)
            assert sent is False and messages == [], f'{mode} deve rejeitar uma cadeia sem CA confiável.'
            checks += 1
            print(f'OK: {mode} rejeita certificado sem CA confiável.', flush=True)
        sent, messages = deliver('smtps-invalid', ca)
        assert sent is False and messages == []
        checks += 1
        print('OK: modo de segurança inválido falha de forma controlada.', flush=True)
        print(f'{checks} verificações de transporte SMTP aprovadas.')


if __name__ == '__main__':
    main()
