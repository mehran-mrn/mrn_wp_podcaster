[CmdletBinding()]
param(
	[string]$Version = '0.2.2'
)

$ErrorActionPreference = 'Stop'
$pluginRoot = Split-Path -Parent $PSScriptRoot
$destination = Join-Path (Split-Path -Parent $pluginRoot) "mrn-podcaster-$Version.zip"
$staging = Join-Path ([System.IO.Path]::GetTempPath()) ("mrnp-" + [guid]::NewGuid().ToString('N'))
$packageRoot = Join-Path $staging 'mrn-podcaster'

try {
	New-Item -ItemType Directory -Path $packageRoot -Force | Out-Null
	Get-ChildItem -LiteralPath $pluginRoot -Force |
		Where-Object {
			$_.Name -notin @(
				'.git',
				'.github',
				'.gitignore',
				'vendor',
				'tests',
				'tools',
				'composer.json',
				'composer.lock',
				'phpcs.xml.dist'
			)
		} |
		Copy-Item -Destination $packageRoot -Recurse -Force

	Add-Type -AssemblyName System.IO.Compression
	Add-Type -AssemblyName System.IO.Compression.FileSystem
	if (Test-Path -LiteralPath $destination) {
		Remove-Item -LiteralPath $destination -Force
	}
	$archiveStream = [System.IO.File]::Open(
		$destination,
		[System.IO.FileMode]::CreateNew,
		[System.IO.FileAccess]::ReadWrite,
		[System.IO.FileShare]::None
	)
	try {
		$archive = [System.IO.Compression.ZipArchive]::new(
			$archiveStream,
			[System.IO.Compression.ZipArchiveMode]::Create,
			$false
		)
		try {
			Get-ChildItem -LiteralPath $packageRoot -File -Recurse | ForEach-Object {
				$relativePath = $_.FullName.Substring($staging.Length).TrimStart(
					[System.IO.Path]::DirectorySeparatorChar,
					[System.IO.Path]::AltDirectorySeparatorChar
				)
				$entryName = $relativePath.Replace('\', '/')
				[System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
					$archive,
					$_.FullName,
					$entryName,
					[System.IO.Compression.CompressionLevel]::Optimal
				) | Out-Null
			}
		}
		finally {
			$archive.Dispose()
		}
	}
	finally {
		$archiveStream.Dispose()
	}
	Write-Host "Created $destination" -ForegroundColor Green
}
finally {
	if (Test-Path -LiteralPath $staging) {
		Remove-Item -LiteralPath $staging -Recurse -Force
	}
}
