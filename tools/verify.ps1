[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$pluginRoot = Split-Path -Parent $PSScriptRoot
$errors = [System.Collections.Generic.List[string]]::new()

Write-Host ''
Write-Host 'MRN Podcaster verification' -ForegroundColor Cyan
Write-Host '=========================='

$phpFiles = @(Get-ChildItem -LiteralPath $pluginRoot -Filter '*.php' -File -Recurse |
	Where-Object { $_.FullName -notmatch '[\\/]vendor[\\/]' })
foreach ($file in $phpFiles) {
	& php -l $file.FullName | Out-Null
	if ($LASTEXITCODE -ne 0) {
		$errors.Add("PHP syntax: $($file.FullName)")
	}
}
Write-Host "[ ok ] PHP syntax ($($phpFiles.Count) files)" -ForegroundColor Green

$jsFiles = @(Get-ChildItem -LiteralPath (Join-Path $pluginRoot 'assets\js') -Filter '*.js' -File)
if (Get-Command node -ErrorAction SilentlyContinue) {
	foreach ($file in $jsFiles) {
		& node --check $file.FullName
		if ($LASTEXITCODE -ne 0) {
			$errors.Add("JavaScript syntax: $($file.FullName)")
		}
	}
	Write-Host "[ ok ] JavaScript syntax ($($jsFiles.Count) files)" -ForegroundColor Green
}
else {
	Write-Host '[skip] Node.js is unavailable; JavaScript syntax not checked.' -ForegroundColor Yellow
}

& php (Join-Path $pluginRoot 'tests\test-normalizer.php')
if ($LASTEXITCODE -ne 0) {
	$errors.Add('Normalizer tests failed.')
}
else {
	Write-Host '[ ok ] Normalizer tests' -ForegroundColor Green
}

& php (Join-Path $pluginRoot 'tests\test-feed-client.php')
if ($LASTEXITCODE -ne 0) {
	$errors.Add('Feed client tests failed.')
}
else {
	Write-Host '[ ok ] Feed client tests' -ForegroundColor Green
}

$forbidden = @(
	'eval\s*\(',
	'base64_decode\s*\(',
	'wp_remote_get\s*\(',
	'file_get_contents\s*\(\s*["'']https?://'
)
$sourceFiles = @(Get-ChildItem -LiteralPath $pluginRoot -File -Recurse |
	Where-Object { $_.Extension -in @('.php', '.js') -and $_.FullName -notmatch '[\\/](vendor|tests)[\\/]' })
foreach ($file in $sourceFiles) {
	$content = Get-Content -LiteralPath $file.FullName -Raw
	foreach ($pattern in $forbidden) {
		if ($content -match $pattern) {
			$errors.Add("Forbidden pattern '$pattern': $($file.FullName)")
		}
	}
}
Write-Host "[ ok ] Security patterns ($($sourceFiles.Count) files)" -ForegroundColor Green

if ($errors.Count -gt 0) {
	Write-Host ''
	foreach ($problem in $errors) {
		Write-Host "[fail] $problem" -ForegroundColor Red
	}
	exit 1
}

Write-Host ''
Write-Host 'PASSED' -ForegroundColor Green
