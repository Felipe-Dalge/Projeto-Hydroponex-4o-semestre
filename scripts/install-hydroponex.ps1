[CmdletBinding()]
param([string]$XamppPath='C:\xampp',[int]$DatabasePort=0,[int]$HttpPort=0,[switch]$NoBrowser,[switch]$ConfigureApache)
$ErrorActionPreference='Stop'
$oldEnvironment=@{}
foreach($k in @('HYDROPONEX_DB_HOST','HYDROPONEX_DB_PORT','HYDROPONEX_DB_NAME','HYDROPONEX_DB_USER','HYDROPONEX_DB_PASSWORD','HYDROPONEX_AUTH_KEY')){$oldEnvironment[$k]=[Environment]::GetEnvironmentVariable($k,'Process')}
$repo=Split-Path $PSScriptRoot -Parent
function Ask-Secret([string]$Prompt) {
    $secure=Read-Host $Prompt -AsSecureString
    $ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
}
function Write-Utf8([string]$Path,[string]$Value) { [IO.File]::WriteAllText($Path,$Value,(New-Object Text.UTF8Encoding $false)) }
function Tcp-Open([int]$Port) { $client=New-Object Net.Sockets.TcpClient;try{$task=$client.ConnectAsync('127.0.0.1',$Port);return $task.Wait(1000) -and $client.Connected}catch{return $false}finally{$client.Dispose()} }
function Invoke-Database($InputData) {
    $info=New-Object Diagnostics.ProcessStartInfo
    $info.FileName=$php;$info.Arguments='"'+(Join-Path $PSScriptRoot 'install-database.php')+'"'
    $info.UseShellExecute=$false;$info.CreateNoWindow=$true;$info.RedirectStandardInput=$true;$info.RedirectStandardOutput=$true;$info.RedirectStandardError=$true
    $info.StandardOutputEncoding=[Text.Encoding]::UTF8
    $proc=New-Object Diagnostics.Process;$proc.StartInfo=$info
    [void]$proc.Start();$proc.StandardInput.Write(($InputData|ConvertTo-Json -Depth 5 -Compress));$proc.StandardInput.Close()
    $raw=$proc.StandardOutput.ReadToEnd();$null=$proc.StandardError.ReadToEnd();$proc.WaitForExit()
    try{$result=$raw|ConvertFrom-Json}catch{throw 'PHP não retornou um diagnóstico válido. Confira extensões e permissões de arquivos.'}
    if(!$result.success){throw $result.message};return $result
}
function Invoke-Apache([string]$Action) {
    $info=New-Object Diagnostics.ProcessStartInfo
    $info.FileName=$apache;$info.Arguments='-f "'+$httpConfig+'" '+$Action
    $info.UseShellExecute=$false;$info.CreateNoWindow=$true;$info.RedirectStandardError=$true;$info.RedirectStandardOutput=$true
    $proc=New-Object Diagnostics.Process;$proc.StartInfo=$info;[void]$proc.Start()
    $null=$proc.StandardOutput.ReadToEnd();$null=$proc.StandardError.ReadToEnd();$proc.WaitForExit();return $proc.ExitCode
}
function Http-Check([string]$Url,[int[]]$Expected) {
    try{$response=Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 15; $status=[int]$response.StatusCode;$body=$response.Content}
    catch{if(!$_.Exception.Response){throw "Sem resposta HTTP: $Url"};$status=[int]$_.Exception.Response.StatusCode;$body=''}
    if($Expected -notcontains $status){throw "HTTP $status inesperado em $Url. Confira Apache, rotas e permissões. Se AllowOverride estiver desabilitado, reexecute com -ConfigureApache."}
    return $body
}
try {
    Write-Host 'HYDROPONEX - instalação guiada no XAMPP'
    if(!(Test-Path -LiteralPath $XamppPath)){$XamppPath=Read-Host 'Informe a pasta do XAMPP'}
    $XamppPath=(Resolve-Path -LiteralPath $XamppPath).Path
    $php=Join-Path $XamppPath 'php/php.exe';$apache=Join-Path $XamppPath 'apache/bin/httpd.exe';$maria=Join-Path $XamppPath 'mysql/bin/mysqld.exe'
    foreach($exe in @($php,$apache,$maria)){if(!(Test-Path -LiteralPath $exe)){throw "Componente do XAMPP ausente: $exe"}}
    $app=Join-Path $repo 'Hydroponex_2/hydro/Hydroponexteste2';if(!(Test-Path -LiteralPath "$app/index.html")){throw 'Pasta da aplicação não encontrada no repositório.'}
    $requirements=& $php (Join-Path $PSScriptRoot 'check-environment.php')
    if($LASTEXITCODE -ne 0 -or $requirements -ne 'OK'){throw 'Habilite PHP >=8.1, pdo_mysql, session e json no XAMPP.'}
    $httpConfig=Join-Path $XamppPath 'apache/conf/httpd.conf';$original=[IO.File]::ReadAllText($httpConfig)
    $dbConfig=Join-Path $XamppPath 'mysql/bin/my.ini'
    if(!$DatabasePort){$match=[regex]::Match([IO.File]::ReadAllText($dbConfig),'(?m)^\s*port\s*=\s*(\d+)');if(!$match.Success){throw 'Porta do MariaDB não encontrada; use -DatabasePort.'};$DatabasePort=[int]$match.Groups[1].Value}
    if(!$HttpPort){$match=[regex]::Match($original,'(?m)^\s*Listen\s+(?:[^\s:]+:)?(\d+)');if(!$match.Success){throw 'Porta Apache não encontrada; use -HttpPort.'};$HttpPort=[int]$match.Groups[1].Value}
    foreach($port in @($DatabasePort,$HttpPort)){if($port -lt 1 -or $port -gt 65535){throw 'Porta inválida.'}}
    if(!(Tcp-Open $DatabasePort)){
        $configured=[regex]::Match([IO.File]::ReadAllText($dbConfig),'(?m)^\s*port\s*=\s*(\d+)')
        if(!$configured.Success -or [int]$configured.Groups[1].Value -ne $DatabasePort){throw 'MariaDB desligado na porta indicada. Inicie essa instância separadamente; o instalador não iniciará outro banco por engano.'}
        Write-Host 'MariaDB desligado. Tentando iniciar os binários locais...'
        Start-Process -FilePath $maria -ArgumentList @("--defaults-file=`"$dbConfig`"") -WorkingDirectory (Split-Path $maria) -WindowStyle Hidden
        for($i=0;$i -lt 15 -and !(Tcp-Open $DatabasePort);$i++){Start-Sleep -Seconds 1}
        if(!(Tcp-Open $DatabasePort)){throw 'Inicie MySQL no XAMPP Control Panel e confira seus logs. Nenhum arquivo do banco deve ser apagado.'}
    }
    if(Tcp-Open $HttpPort){$listener=Get-NetTCPConnection -State Listen -LocalPort $HttpPort -ErrorAction SilentlyContinue | Select-Object -First 1;if($listener -and (Get-Process -Id $listener.OwningProcess).ProcessName -ne 'httpd'){throw "Porta $HttpPort ocupada por outro programa. Ajuste o Apache e execute novamente."}}
    $private=Join-Path $XamppPath 'hydroponex-private';$privateFile=Join-Path $private 'config.local.json'
    New-Item -ItemType Directory -Path $private -Force | Out-Null
    $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    & icacls.exe $private /inheritance:r /grant:r "*${sid}:(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
    if($LASTEXITCODE -ne 0){throw 'Não foi possível proteger a pasta privada. Execute com permissão de escrita no XAMPP.'}
    $prior=$null;if(Test-Path -LiteralPath $privateFile){$prior=Get-Content -LiteralPath $privateFile -Raw | ConvertFrom-Json}
    $admin=Read-Host 'Usuário administrador do MariaDB (Enter para root)';if(!$admin){$admin='root'}
    $adminPassword=Ask-Secret 'Senha administrativa (entrada protegida; Enter se realmente não houver senha)'
    $appPassword='';if(!$prior){$appPassword=Ask-Secret 'Se hydroponex_app já existe, informe sua senha; caso contrário pressione Enter'}
    $demo=(Read-Host 'Deseja preparar dados fictícios para demonstração? (S/N)') -match '^[sS]$'
    $demoPassword='';if($demo -and !($prior -and $prior.demo)){$demoPassword=Ask-Secret 'Senha para ana@example.test (12 a 72 caracteres; não será exibida nem gravada em texto puro)'}
    Write-Host 'Validando MariaDB, banco e conta da aplicação...'
    $result=Invoke-Database @{port=$DatabasePort;admin=$admin;password=$adminPassword;appPassword=$appPassword;prior=$prior;demo=$demo;demoPassword=$demoPassword;privatePath=$privateFile}
    $adminPassword=$null;$appPassword=$null;$demoPassword=$null
    $stamp=Get-Date -Format 'yyyyMMdd-HHmmss-fff'
    if(Test-Path -LiteralPath $privateFile){Copy-Item -LiteralPath $privateFile -Destination "$privateFile.$stamp.bak"}
    Write-Utf8 "$privateFile.tmp" ($result.settings|ConvertTo-Json -Depth 4)
    Move-Item -LiteralPath "$privateFile.tmp" -Destination $privateFile -Force
    $target=Join-Path $XamppPath 'htdocs/hydroponex'
    if([IO.Path]::GetFullPath($app).TrimEnd('\') -eq [IO.Path]::GetFullPath($target).TrimEnd('\')){throw 'Execute o instalador a partir do clone, fora da pasta publicada.'}
    if(Test-Path -LiteralPath $target){Copy-Item -LiteralPath $target -Destination (Join-Path $private "app-backup-$stamp") -Recurse}
    New-Item -ItemType Directory -Path $target -Force | Out-Null
    foreach($file in (Get-ChildItem -LiteralPath $app -Recurse -File -Force)){
        if($file.Name -like '.env*' -or $file.Name -like '*.local.*' -or $file.Name -match '\.(bak|zip|sql|log)$'){continue}
        $relative=$file.FullName.Substring($app.Length).TrimStart('\','/')
        $dest=Join-Path $target $relative;New-Item -ItemType Directory -Path (Split-Path $dest) -Force | Out-Null;Copy-Item -LiteralPath $file.FullName -Destination $dest -Force
    }
    $escaped=$privateFile.Replace('\','/').Replace("'","\'")
    Write-Utf8 (Join-Path $target 'php/config/installation.local.php') ("<?php return '$escaped';")
    # O XAMPP padrão já permite .htaccess: configuração específica, sem reinício.
    $accessFile=Join-Path $target '.htaccess'
    Write-Utf8 $accessFile ("Require local`r`n"+[IO.File]::ReadAllText($accessFile))
    if($ConfigureApache) {
    $include=Join-Path $XamppPath 'apache/conf/extra/hydroponex-installer.conf'
    $oldInclude=$null;if(Test-Path -LiteralPath $include){$oldInclude=[IO.File]::ReadAllText($include);Copy-Item -LiteralPath $include -Destination (Join-Path $private "apache-include-$stamp.bak")}
    $normalizedTarget=$target.Replace('\','/')
    $includeText="<Directory `"$normalizedTarget`">`r`nAllowOverride All`r`nRequire local`r`n</Directory>`r`n"
    Copy-Item -LiteralPath $httpConfig -Destination (Join-Path $private "httpd-$stamp.bak")
    Write-Utf8 $include $includeText
    $line='Include "'+$include.Replace('\','/')+'"'
    if(!$original.Contains($line)){Write-Utf8 $httpConfig ($original+"`r`n"+$line+"`r`n")}
    $syntax=Invoke-Apache '-t'
    if($syntax -ne 0){Write-Utf8 $httpConfig $original;if($null -ne $oldInclude){Write-Utf8 $include $oldInclude}else{Remove-Item -LiteralPath $include};throw 'Sintaxe Apache inválida. Configuração original restaurada; consulte os logs e execute novamente.'}
    } elseif((Invoke-Apache '-t') -ne 0) { throw 'Configuração Apache existente inválida. Nenhuma configuração global foi alterada.' }
    # Diagnóstico CLI usa exatamente a configuração privada que será lida pelo Apache.
    foreach($key in @('HYDROPONEX_DB_HOST','HYDROPONEX_DB_PORT','HYDROPONEX_DB_NAME','HYDROPONEX_DB_USER','HYDROPONEX_DB_PASSWORD','HYDROPONEX_AUTH_KEY')){[Environment]::SetEnvironmentVariable($key,[string]$result.settings.$key,'Process')}
    & $php (Join-Path $repo 'database/diagnostico_mariadb.php') --schema
    if($LASTEXITCODE -ne 0){throw 'Diagnóstico CLI falhou; instalação incompleta.'}
    $integrity=& $php (Join-Path $repo 'database/tests/integridade.php')
    if($LASTEXITCODE -ne 0){throw 'Integridade referencial/CHECK falhou. Instalação incompleta; testes revertidos.'}
    $integrity | Select-Object -Last 1
    if(Tcp-Open $HttpPort){if($ConfigureApache -and (Invoke-Apache '-k restart') -ne 0){$null=Read-Host 'Configuração validada. Pare e inicie Apache no XAMPP Control Panel, depois pressione Enter'}}else{Start-Process -FilePath $apache -ArgumentList @("-f", "`"$httpConfig`"") -WorkingDirectory (Split-Path $apache) -WindowStyle Hidden}
    $base='http://localhost'+$(if($HttpPort -eq 80){''}else{":$HttpPort"})+'/hydroponex/'
    Start-Sleep -Seconds 2
    $null=Http-Check $base @(200)
    $session=Http-Check ($base+'php/api/session.php') @(200)
    if(!(($session|ConvertFrom-Json).csrfToken)){throw 'API de sessão não retornou token CSRF.'}
    $null=Http-Check ($base+'php/api/dashboard.php?horta=1') @(401)
    foreach($path in @('.env','php/config/installation.local.php','php/config/database.php','php/services/users.php','php/storage/plantas.json','server/router.php')){$null=Http-Check ($base+$path) @(403,404)}
    Write-Host "`nHYDROPONEX - INSTALAÇÃO CONCLUÍDA"
    foreach($check in @('XAMPP encontrado','PHP validado',"MariaDB $($result.version) validado",'Banco e estrutura SQL validados','Permissões verificadas','Conexão PDO funcionando','Autenticação configurada','Aplicação publicada','Testes HTTP e arquivos privados concluídos')){Write-Host "[OK] $check"}
    if($result.settings.demo){Write-Host 'Demonstração: ana@example.test; use a senha informada na primeira instalação.'}
    Write-Host "Acesse: $base";if(!$NoBrowser){Start-Process $base}
    exit 0
}catch {Write-Host "`n[ERRO] $($_.Exception.Message)" -ForegroundColor Red;Write-Host 'Instalação incompleta. Dados não foram apagados. Corrija o problema e execute novamente.';exit 1}
finally {foreach($key in @('HYDROPONEX_DB_HOST','HYDROPONEX_DB_PORT','HYDROPONEX_DB_NAME','HYDROPONEX_DB_USER','HYDROPONEX_DB_PASSWORD','HYDROPONEX_AUTH_KEY')){[Environment]::SetEnvironmentVariable($key,$oldEnvironment[$key],'Process')};$adminPassword=$null;$result=$null}
