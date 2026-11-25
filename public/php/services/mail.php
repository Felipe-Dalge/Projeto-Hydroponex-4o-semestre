<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

/** A configuração do transporte vem do processo PHP/Apache. */
function send_team_invitation_mail(string $recipient, string $token): bool
{
    try {
        $autoload = (function_exists('hydroponex_setting') ? hydroponex_setting('HYDROPONEX_MAIL_AUTOLOAD') : false)
            ?: (getenv('HYDROPONEX_MAIL_AUTOLOAD') ?: dirname(__DIR__, 3) . '/vendor/autoload.php');
        $path = realpath($autoload);
        $public = realpath(dirname(__DIR__, 2));
        if (!$path || !$public || strncasecmp($path, $public . DIRECTORY_SEPARATOR, strlen($public) + 1) === 0) {
            throw new RuntimeException('Autoloader indisponível.');
        }
        $host = (string)getenv('HYDROPONEX_SMTP_HOST');
        $port = filter_var(getenv('HYDROPONEX_SMTP_PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $security = (string)getenv('HYDROPONEX_SMTP_SECURITY');
        $username = (string)getenv('HYDROPONEX_SMTP_USER');
        $password = (string)getenv('HYDROPONEX_SMTP_PASSWORD');
        $from = (string)getenv('HYDROPONEX_SMTP_FROM');
        $name = getenv('HYDROPONEX_SMTP_FROM_NAME') ?: 'Hydroponex';
        $url = (string)getenv('HYDROPONEX_PUBLIC_URL');
        $base = parse_url($url);
        $loopback = ['localhost', '127.0.0.1', '[::1]'];
        $secureUrl = ($base['scheme'] ?? '') === 'https'
            || (($base['scheme'] ?? '') === 'http' && in_array($base['host'] ?? '', $loopback, true));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !$secureUrl
            || isset($base['user']) || isset($base['pass']) || isset($base['query']) || isset($base['fragment'])
            || !preg_match('/\A(?:[a-z0-9.-]+|\[::1\])\z/i', $host) || !$port
            || !in_array($security, ['tls', 'smtps', 'none'], true)
            || ($security === 'none' && (!in_array($host, $loopback, true) || $username !== '' || $password !== ''))
            || (($username === '') !== ($password === '')) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Configuração SMTP inválida.');
        }
        require_once $path;
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->Port = $port;
        $mailer->SMTPSecure = match ($security) {
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            'smtps' => PHPMailer::ENCRYPTION_SMTPS,
            'none' => '',
        };
        $mailer->SMTPAutoTLS = false;
        $mailer->SMTPAuth = $username !== '';
        $mailer->Username = $username;
        $mailer->Password = $password;
        $mailer->Timeout = 8;
        $mailer->getSMTPInstance()->Timelimit = 8;
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($from, $name);
        $mailer->addAddress($recipient);
        $mailer->Subject = 'Convite para participar de uma equipe no Hydroponex';
        $link = rtrim($url, '/') . '/convite.html#' . $token;
        $mailer->Body = "Você recebeu um convite para trabalhar em hortas no Hydroponex.\n\n"
            . "Abra o endereço abaixo e entre ou cadastre-se com o e-mail que recebeu esta mensagem. "
            . "Você poderá conferir as hortas antes de aceitar ou recusar.\n\n$link\n\n"
            . "O convite é válido por sete dias. Se não o esperava, ignore esta mensagem.";
        return $mailer->send();
    } catch (Throwable) {
        // Respostas SMTP podem reproduzir dados enviados, inclusive o link.
        error_log('[Hydroponex] invitation_mail_failed');
        return false;
    }
}
