[CmdletBinding()]
param(
    [string]$XamppPath='C:\xampp',
    [string]$SourcePath='',
    [string]$ApplicationPath='',
    [string]$ConfigurationPath='',
    [int]$HttpPort=0,
    [ValidateRange(0,300)][int]$MaintenanceSeconds=35,
    [switch]$FromGitHub,
    [switch]$CheckOnly,
    [switch]$NoBrowser
)
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$maintenance=$false
$databaseStarted=$false
$lock=$null
$oldTls=[Net.ServicePointManager]::SecurityProtocol
$oldModulePath=$env:PSModulePath
$env:PSModulePath="$PSHOME\Modules;"+$env:PSModulePath
function Write-Utf8([string]$Path,[string]$Value) {
    [IO.File]::WriteAllText($Path,$Value,(New-Object Text.UTF8Encoding $false))
}
function Read-Secret([string]$Prompt) {
    $secret=Read-Host $Prompt -AsSecureString
    $pointer=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
    try {[Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)}
    finally {[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)}
}
function Assert-PlainTree([string]$Path) {
    $root=Get-Item -LiteralPath $Path -Force
    if($root.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'Links e junções não são aceitos nas pastas da atualização.'}
    foreach($item in Get-ChildItem -LiteralPath $Path -Force) {
        if($item.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'Links e junções não são aceitos nas pastas da atualização.'}
        if($item.PSIsContainer){Assert-PlainTree $item.FullName}
    }
}
function Invoke-Database($Data) {
    $info=New-Object Diagnostics.ProcessStartInfo
    $info.FileName=$php
    $info.Arguments='"'+(Join-Path $PSScriptRoot 'update-database.php')+'"'
    $info.UseShellExecute=$false
    $info.CreateNoWindow=$true
    $info.RedirectStandardInput=$true
    $info.RedirectStandardOutput=$true
    $info.RedirectStandardError=$true
    $info.StandardOutputEncoding=[Text.Encoding]::UTF8
    $process=New-Object Diagnostics.Process
    $process.StartInfo=$info
    [void]$process.Start()
    $errors=$process.StandardError.ReadToEndAsync()
    $writer=[IO.StreamWriter]::new($process.StandardInput.BaseStream,[Text.UTF8Encoding]::new($false))
    $writer.Write(($Data|ConvertTo-Json -Depth 8 -Compress))
    $writer.Close()
    $raw=$process.StandardOutput.ReadToEnd()
    $process.WaitForExit()
    $null=$errors.Result
    try{$result=$raw|ConvertFrom-Json}catch{throw 'PHP não retornou um diagnóstico válido. Confira extensões e permissões.'}
    if(!$result.success){throw $result.message}
    return $result
}
function Run-Program([string]$Executable,[string[]]$Arguments) {
    $info=New-Object Diagnostics.ProcessStartInfo
    $info.FileName=$Executable
    $info.Arguments=($Arguments|ForEach-Object {'"'+$_.Replace('"','\"')+'"'}) -join ' '
    $info.UseShellExecute=$false
    $info.CreateNoWindow=$true
    $info.RedirectStandardOutput=$true
    $info.RedirectStandardError=$true
    $process=New-Object Diagnostics.Process
    $process.StartInfo=$info
    [void]$process.Start()
    $output=$process.StandardOutput.ReadToEndAsync()
    $errors=$process.StandardError.ReadToEndAsync()
    $process.WaitForExit()
    $null=$output.Result
    $null=$errors.Result
    if($process.ExitCode -ne 0){throw ('Falha ao executar '+[IO.Path]::GetFileName($Executable)+'. Confira requisitos e permissões; nenhum detalhe de credenciais foi exibido.')}
}
function Http-Check([string]$Url,[int[]]$Expected) {
    try{$response=Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 20;$status=[int]$response.StatusCode;$body=$response.Content}
    catch{if(!$_.Exception.Response){throw "Apache não respondeu: $Url"};$status=[int]$_.Exception.Response.StatusCode;$body=''}
    if($Expected -notcontains $status){throw "Resposta HTTP $status inesperada em $Url. Confira Apache, rotas e AllowOverride."}
    return $body
}
function Same-PublicFiles([string]$Source,[string]$Target) {
    foreach($file in Get-ChildItem -LiteralPath $Source -Recurse -File -Force) {
        if($file.Name -like '*.local.*' -or $file.Name -like '.env*'){continue}
        $relative=$file.FullName.Substring($Source.Length).TrimStart('\','/')
        $installed=Join-Path $Target $relative
        if(!(Test-Path -LiteralPath $installed -PathType Leaf)){return $false}
        if($relative -eq '.htaccess'){
            if(!([IO.File]::ReadAllText($installed).Contains([IO.File]::ReadAllText($file.FullName).Trim()))){return $false}
        } elseif((Get-FileHash -LiteralPath $file.FullName).Hash -ne (Get-FileHash -LiteralPath $installed).Hash){return $false}
    }
    return $true
}
function Client-Option([string]$Value) {
    return '"'+$Value.Replace('\','\\').Replace('"','\"').Replace([string][char]13,'\r').Replace([string][char]10,'\n')+'"'
}
function Prepare-Mailer([string]$Source,[string]$Runtime) {
    New-Item -ItemType Directory -Path $Runtime -Force|Out-Null
    $vendor=Join-Path $Source 'vendor'
    if(Test-Path -LiteralPath "$vendor/autoload.php"){
        Assert-PlainTree $vendor
        Copy-Item -LiteralPath $vendor -Destination $Runtime -Recurse
    } else {
        foreach($name in @('composer.json','composer.lock')){
            if(!(Test-Path -LiteralPath "$Source/$name")){throw 'Dependências de e-mail ausentes no pacote.'}
            Copy-Item -LiteralPath "$Source/$name" -Destination $Runtime
        }
        Write-Host 'Preparando as dependências de e-mail em uma pasta privada...'
        $installer=Join-Path $Runtime 'composer-setup.php'
        Invoke-WebRequest 'https://getcomposer.org/installer' -OutFile $installer -UseBasicParsing
        $signatureBody=(Invoke-WebRequest 'https://composer.github.io/installer.sig' -UseBasicParsing).Content
        $signature=if($signatureBody -is [byte[]]){[Text.Encoding]::UTF8.GetString($signatureBody)}else{[string]$signatureBody}
        $signature=$signature.Trim()
        if((Get-FileHash -LiteralPath $installer -Algorithm SHA384).Hash.ToLowerInvariant() -ne $signature.ToLowerInvariant()){throw 'Assinatura do instalador Composer inválida.'}
        Run-Program $php @($installer,('--install-dir='+$Runtime),'--filename=composer.phar','--quiet')
        Run-Program $php @('-d','extension=zip',"$Runtime/composer.phar",'install','--no-dev','--no-interaction','--prefer-dist','--no-scripts',('--working-dir='+$Runtime))
    }
    $probe=Join-Path $Runtime 'verify.php'
    Write-Utf8 $probe ("<?php require __DIR__.'/vendor/autoload.php'; exit(class_exists('PHPMailer\PHPMailer\PHPMailer')?0:1);")
    Run-Program $php @($probe)
    return (Join-Path $Runtime 'vendor/autoload.php').Replace('\','/')
}
try {
    if($PSVersionTable.PSEdition -eq 'Desktop'){
        # Evita importar módulos do PowerShell 7 quando o BAT é chamado por outro programa.
        Import-Module "$PSHOME\Modules\Microsoft.PowerShell.Utility\Microsoft.PowerShell.Utility.psd1" -Force
        Import-Module "$PSHOME\Modules\Microsoft.PowerShell.Management\Microsoft.PowerShell.Management.psd1" -Force
    }
    Write-Host 'HYDROPONEX - verificação e atualização no XAMPP'
    $XamppPath=(Resolve-Path -LiteralPath $XamppPath).Path
    $php=Join-Path $XamppPath 'php/php.exe'
    $dump=Join-Path $XamppPath 'mysql/bin/mysqldump.exe'
    foreach($exe in @($php,$dump)){if(!(Test-Path -LiteralPath $exe)){throw 'PHP ou ferramenta de backup do XAMPP não encontrada.'}}
    if(!$ApplicationPath){$ApplicationPath=Join-Path $XamppPath 'htdocs/hydroponex'}
    if(!$ConfigurationPath){$ConfigurationPath=Join-Path $XamppPath 'hydroponex-private/config.local.json'}
    $app=(Resolve-Path -LiteralPath $ApplicationPath).Path.TrimEnd('\')
    $config=(Resolve-Path -LiteralPath $ConfigurationPath).Path
    $htdocs=[IO.Path]::GetFullPath((Join-Path $XamppPath 'htdocs')).TrimEnd('\')
    if($config.StartsWith($htdocs+'\',[StringComparison]::OrdinalIgnoreCase) -or $config.StartsWith($app+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'A configuração deve permanecer fora da pasta pública.'}
    if(!(Test-Path -LiteralPath "$app/index.html") -or !(Test-Path -LiteralPath "$app/php/api/session.php")){throw 'Instalação não reconhecida. Use uma instalação Hydroponex com PDO e configuração privada do instalador XAMPP.'}
    Assert-PlainTree $app
    $private=Split-Path $config -Parent
    $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    & icacls.exe $private /inheritance:r /grant:r ("*"+$sid+':(OI)(CI)F') '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F'|Out-Null
    if($LASTEXITCODE -ne 0){throw 'Não foi possível proteger a pasta privada.'}
    try{$lock=[IO.File]::Open((Join-Path $private 'update.lock'),[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)}
    catch{throw 'Outro atualizador está em execução nesta instalação.'}
    $settings=Get-Content -LiteralPath $config -Raw|ConvertFrom-Json
    if($settings.HYDROPONEX_AUTH_KEY -notmatch '^[a-fA-F0-9]{64}$'){throw 'Chave de autenticação inválida; restaure a configuração original. Não gere outra chave para contornar o erro.'}
    if(!$HttpPort){
        $apacheConfig=Get-Content -LiteralPath "$XamppPath/apache/conf/httpd.conf" -Raw
        $match=[regex]::Match($apacheConfig,'(?m)^\s*Listen\s+(?:[^\s:]+:)?(\d+)')
        if(!$match.Success){throw 'Informe a porta do Apache com -HttpPort.'}
        $HttpPort=[int]$match.Groups[1].Value
    }
    if($HttpPort -lt 1 -or $HttpPort -gt 65535){throw 'Porta HTTP inválida.'}
    $url='http://localhost:'+ $HttpPort+'/'+[Uri]::EscapeDataString((Split-Path $app -Leaf))+'/'
    $access=Join-Path $app '.htaccess'
    $originalAccess=if(Test-Path -LiteralPath $access){[IO.File]::ReadAllText($access)}else{''}
    $recovering=$originalAccess.Contains('# HYDROPONEX UPDATE MAINTENANCE')
    $null=Http-Check $url $(if($recovering){@(200,403,503)}else{@(200)})
    $work=Join-Path $private ('update-'+(Get-Date -Format yyyyMMdd-HHmmss)+'-'+[Guid]::NewGuid().ToString('N').Substring(0,8))
    New-Item -ItemType Directory -Path $work|Out-Null
    [Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12
    $commit=$null
    if($FromGitHub -and $SourcePath){throw 'Escolha -FromGitHub ou -SourcePath, sem combinar as duas opções.'}
    if(!$SourcePath -and !$FromGitHub){$SourcePath=Split-Path $PSScriptRoot -Parent}
    if($FromGitHub){
        Write-Host 'Consultando a versão publicada no GitHub...'
        $headers=@{'User-Agent'='Hydroponex-Updater';'Accept'='application/vnd.github+json'}
        $release=Invoke-RestMethod 'https://api.github.com/repos/Felipe-Dalge/Projeto-Hydroponex-4o-semestre/commits/main' -Headers $headers
        $commit=$release.sha
        if($commit -notmatch '^[a-f0-9]{40}$'){throw 'Referência GitHub inválida.'}
        $zip=Join-Path $work 'source.zip'
        Invoke-WebRequest ("https://codeload.github.com/Felipe-Dalge/Projeto-Hydroponex-4o-semestre/zip/"+$commit) -OutFile $zip -UseBasicParsing
        Expand-Archive -LiteralPath $zip -DestinationPath "$work/source"
        $roots=@(Get-ChildItem -LiteralPath "$work/source" -Directory)
        if($roots.Count -ne 1){throw 'Pacote GitHub inválido.'}
        $SourcePath=$roots[0].FullName
    }
    $source=(Resolve-Path -LiteralPath $SourcePath).Path.TrimEnd('\')
    if($source -eq $app -or $source.StartsWith($app+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'Extraia o projeto novo fora da pasta publicada.'}
    $public=Join-Path $source 'public'
    foreach($file in @('index.html','.htaccess','php/api/session.php','php/config/database.php')){
        if(!(Test-Path -LiteralPath "$public/$file")){throw 'Pacote Hydroponex incompleto.'}
    }
    Assert-PlainTree $source
    $request=@{mode='inspect';settings=$settings;source=$source}
    $plan=Invoke-Database $request
    $version=$plan.target
    if(Test-Path -LiteralPath "$public/version.json"){
        $metadata=Get-Content -LiteralPath "$public/version.json" -Raw|ConvertFrom-Json
        if($metadata.application -ne 'Hydroponex' -or $metadata.schema -ne $plan.target -or $metadata.version -notmatch '^\d+\.\d+\.\d+$'){throw 'Identificação de versão inválida.'}
        $version=$metadata.version
    }
    if(Test-Path -LiteralPath "$app/version.json"){
        $installed=Get-Content -LiteralPath "$app/version.json" -Raw|ConvertFrom-Json
        if([version]$installed.version -gt [version]$version){throw 'O pacote é mais antigo que a aplicação instalada. Atualização recusada para evitar regressão.'}
    }
    Write-Host "Banco instalado: $($plan.current). Banco do pacote: $($plan.target). Aplicação: $version."
    $journal=Join-Path $private 'update-migrations.json'
    if((Test-Path -LiteralPath $journal) -and ((Get-Content -LiteralPath $journal -Raw|ConvertFrom-Json).status -eq 'executing')){throw 'Existe uma migração interrompida. Consulte o diário e o backup antes de retomar; não apague o diário para repetir o SQL.'}
    if(!$recovering -and @($plan.migrations).Count -eq 0 -and (Same-PublicFiles $public $app)){
        Write-Host 'Sua instalação já contém os arquivos desta versão. Nenhuma alteração necessária.'
        exit 0
    }
    Write-Host ("Migrações pendentes: "+@($plan.migrations).Count+'. Os arquivos públicos serão sincronizados.')
    if($CheckOnly){Write-Host 'Verificação concluída. Aplicação e banco não foram alterados.';exit 0}
    $autoload=Prepare-Mailer $source "$work/runtime"
    $stage=Join-Path $work 'stage'
    Copy-Item -LiteralPath $public -Destination $stage -Recurse
    foreach($file in Get-ChildItem -LiteralPath $stage -Recurse -File -Force){
        if($file.Name -like '*.local.*' -or $file.Name -like '.env*' -or $file.Extension -in @('.sql','.zip','.bak','.log')){
            Remove-Item -LiteralPath $file.FullName
        }
    }
    $privatePhp=$config.Replace('\','/').Replace("'","\'")
    Write-Utf8 "$stage/php/config/installation.local.php" ("<?php return '"+$privatePhp+"';")
    foreach($file in Get-ChildItem -LiteralPath $stage -Filter '*.php' -Recurse){Run-Program $php @('-l',$file.FullName)}
    $admin=Read-Host 'Usuário administrador do MariaDB para backup e migrações [root]'
    if(!$admin){$admin='root'}
    $adminPassword=Read-Secret 'Senha desse usuário MariaDB (não é a senha de login do site)'
    $request.admin=$admin
    $request.password=$adminPassword
    $null=Invoke-Database $request
    $backup=Join-Path $work 'backup'
    New-Item -ItemType Directory -Path $backup|Out-Null
    $gate="# HYDROPONEX UPDATE MAINTENANCE"+[Environment]::NewLine+
        "<IfModule mod_rewrite.c>"+[Environment]::NewLine+"RewriteEngine On"+[Environment]::NewLine+
        "RewriteRule ^ - [R=503,L]"+[Environment]::NewLine+"</IfModule>"+[Environment]::NewLine+
        "<IfModule !mod_rewrite.c>"+[Environment]::NewLine+"Require all denied"+[Environment]::NewLine+"</IfModule>"+[Environment]::NewLine
    Write-Host 'Colocando o site em manutenção e aguardando requisições em andamento...'
    Write-Utf8 $access ($gate+$originalAccess)
    $maintenance=$true
    $null=Http-Check ($url+'php/api/session.php') @(403,503)
    for($i=0;$i -lt $MaintenanceSeconds;$i++){Start-Sleep -Seconds 1}
    Copy-Item -LiteralPath $app -Destination "$backup/public" -Recurse
    Write-Utf8 "$backup/public/.htaccess" $originalAccess
    Copy-Item -LiteralPath $config -Destination "$backup/config.local.json"
    $clientFile=Join-Path $work 'client.cnf'
    try{
        $options=@('[client]','host=127.0.0.1',('port='+$settings.HYDROPONEX_DB_PORT),('user='+(Client-Option $admin)),('password='+(Client-Option $adminPassword)))
        Write-Utf8 $clientFile ($options -join [Environment]::NewLine)
        Run-Program $dump @("--defaults-file=$clientFile",'--single-transaction','--hex-blob','--routines','--events','--triggers','--default-character-set=utf8mb4',("--result-file=$backup/database.sql"),$settings.HYDROPONEX_DB_NAME)
        if(!(Test-Path -LiteralPath "$backup/database.sql") -or (Get-Item -LiteralPath "$backup/database.sql").Length -lt 100){throw 'Backup do banco não foi concluído.'}
    } finally {if(Test-Path -LiteralPath $clientFile){Remove-Item -LiteralPath $clientFile}}
    Write-Host "Backup preservado em: $backup"
    $databaseStarted=$true
    $request.mode='migrate'
    $request.journal=$journal
    $null=Invoke-Database $request
    $null=Invoke-Database @{mode='validate';settings=$settings;source=$source}
    $newSettings=@{}
    foreach($property in $settings.PSObject.Properties){$newSettings[$property.Name]=$property.Value}
    $newSettings.HYDROPONEX_MAIL_AUTOLOAD=$autoload
    Write-Utf8 "$config.tmp" ($newSettings|ConvertTo-Json -Depth 8)
    Move-Item -LiteralPath "$config.tmp" -Destination $config -Force
    # Preserve restrições locais e variáveis SMTP sem copiar regras antigas da aplicação.
    $localRules=([regex]::Matches($originalAccess,'(?m)^\s*(?:Require local|SetEnv HYDROPONEX_[^\r\n]+)\s*$')|ForEach-Object {$_.Value.Trim()}) -join [Environment]::NewLine
    Write-Utf8 "$stage/.htaccess" ($localRules+[Environment]::NewLine+[IO.File]::ReadAllText("$public/.htaccess"))
    $previous=Join-Path $work 'previous-public'
    Move-Item -LiteralPath $app -Destination $previous
    try{Move-Item -LiteralPath $stage -Destination $app}
    catch{Move-Item -LiteralPath $previous -Destination $app;throw}
    $null=Http-Check $url @(200)
    $session=Http-Check ($url+'php/api/session.php') @(200)
    $sessionData=$session|ConvertFrom-Json
    if(!$sessionData.success -or !$sessionData.csrfToken){throw 'A API de sessão não passou na verificação após a atualização.'}
    foreach($path in @('.env','database/hydroponex.sql','php/config/database.php','php/config/installation.local.php','storage/plantas.json')){
        $null=Http-Check ($url+$path) @(403,404)
    }
    Write-Utf8 (Join-Path $private 'installed-version.json') (@{version=$version;schema=$plan.target;commit=$commit;updated=(Get-Date).ToUniversalTime().ToString('o');backup=$backup}|ConvertTo-Json)
    $maintenance=$false
    Write-Host 'Atualização concluída. Dados, usuários e chave de autenticação foram preservados.'
    Write-Host "Acesse: $url"
    Write-Host "Backup: $backup"
    if(!$NoBrowser){Start-Process $url -WindowStyle Hidden}
    exit 0
} catch {
    if($maintenance){
        if(!$databaseStarted){Write-Utf8 $access $originalAccess}
        elseif(Test-Path -LiteralPath $app){Write-Utf8 $access ($gate+[IO.File]::ReadAllText($access))}
    }
    Write-Host ("[ERRO] "+$_.Exception.Message) -ForegroundColor Red
    if($databaseStarted){Write-Host "Site mantido em manutenção. Não repita uma migração parcial. Consulte $journal e o backup $backup. Siga o guia de recuperação."}
    else{Write-Host 'Atualização não aplicada. Os dados não foram apagados.'}
    exit 1
} finally {
    $adminPassword=$null
    if($request){$request.password=$null}
    if($lock){$lock.Dispose()}
    [Net.ServicePointManager]::SecurityProtocol=$oldTls
    $env:PSModulePath=$oldModulePath
}
