$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$php = Join-Path $projectRoot 'tools\php\php.exe'

if (-not (Test-Path -LiteralPath $php)) {
    throw "PHP não encontrado em: $php"
}

Set-Location -LiteralPath $projectRoot
Write-Host 'NyxCloud disponível em http://127.0.0.1:8000/index.html'
Write-Host 'Pressione Ctrl+C para parar o servidor.'
& $php -S 127.0.0.1:8000 -t $projectRoot
