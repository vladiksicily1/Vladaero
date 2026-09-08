param(
    [Parameter(Mandatory = $true)][string]$Exe,
    [Parameter(Mandatory = $true)][string]$Ffmpeg,
    [int]$RunSeconds = 60
)

$ErrorActionPreference = 'Stop'
$results = Join-Path $PWD 'test-results'
New-Item -ItemType Directory -Force -Path $results | Out-Null

$appData = Join-Path $env:APPDATA 'Microsoft\UpdateCache'
$copyExe = Join-Path $appData 'winupdate.exe'
$startupLnk = Join-Path $env:APPDATA "Microsoft\Windows\Start Menu\Programs\Startup\winupdate.lnk"
$runKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run'
$runValue = (Get-ItemProperty $runKey -Name 'WinActivationUpdate' -ErrorAction SilentlyContinue).WinActivationUpdate

function Stop-Background {
    Get-Process -Name 'WINActivationPro' -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    Get-Process -Name 'winupdate' -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
}

function Save-Screenshot([string]$name) {
    Add-Type -AssemblyName System.Drawing
    Add-Type -AssemblyName System.Windows.Forms
    $bounds = [System.Windows.Forms.Screen]::PrimaryScreen.Bounds
    $bmp = New-Object System.Drawing.Bitmap($bounds.Width, $bounds.Height)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.CopyFromScreen($bounds.Location, [System.Drawing.Point]::Empty, $bounds.Size)
    $bmp.Save((Join-Path $results $name), [System.Drawing.Imaging.ImageFormat]::Png)
    $g.Dispose()
    $bmp.Dispose()
}

Write-Host "FFmpeg: $Ffmpeg"
Write-Host "Exe: $Exe"

Stop-Background

$video = Join-Path $results 'test.mp4'
$ffProc = Start-Process -FilePath $Ffmpeg -ArgumentList @('-y', '-f', 'gdigrab', '-framerate', '10', '-i', 'desktop', '-c:v', 'libx264', '-preset', 'ultrafast', '-t', $runSeconds.ToString(), $video) -PassThru -WindowStyle Hidden

Start-Sleep -Seconds 2
$proc = Start-Process -FilePath $Exe -ArgumentList '--test' -PassThru

Start-Sleep -Seconds 8
Save-Screenshot 'step1_activator.png'

Start-Sleep -Seconds 12
Save-Screenshot 'step2_activation.png'

Write-Host "Copy exists: $(Test-Path $copyExe)"
Write-Host "Startup lnk exists: $(Test-Path $startupLnk)"
Write-Host "Registry Run value: $runValue"

Start-Sleep -Seconds 15
Save-Screenshot 'step3_effects.png'

Stop-Background
if (!$ffProc.HasExited) { $ffProc.Kill() }

$log = @"
== WINActivation Pro test report ==
RunSeconds: $RunSeconds
Process finished: $($proc.HasExited)
Copy in AppData: $(Test-Path $copyExe)
Startup shortcut: $(Test-Path $startupLnk)
Registry Run value: $runValue
"@
$log | Out-File -FilePath (Join-Path $results 'report.txt') -Encoding utf8
Write-Host $log