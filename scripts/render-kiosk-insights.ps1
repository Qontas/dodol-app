param([Parameter(Mandatory=$true)][string]$PdfPath, [Parameter(Mandatory=$true)][string]$OutputDirectory)
# Local Windows renderer; does not send reports anywhere.
Add-Type -AssemblyName System.Runtime.WindowsRuntime
$null=[Windows.Storage.StorageFile,Windows.Storage,ContentType=WindowsRuntime]
$null=[Windows.Data.Pdf.PdfDocument,Windows.Data.Pdf,ContentType=WindowsRuntime]
$null=[Windows.Storage.Streams.InMemoryRandomAccessStream,Windows.Storage.Streams,ContentType=WindowsRuntime]
$asTask=([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object { $_.Name -eq 'AsTask' -and $_.IsGenericMethod -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1' } | Select-Object -First 1)
function Await($op,$type) { $task=$asTask.MakeGenericMethod($type).Invoke($null,@($op)); $task.Wait(); $task.Result }
$file=Await ([Windows.Storage.StorageFile]::GetFileFromPathAsync($PdfPath)) ([Windows.Storage.StorageFile])
$pdf=Await ([Windows.Data.Pdf.PdfDocument]::LoadFromFileAsync($file)) ([Windows.Data.Pdf.PdfDocument])
Write-Output "Pages: $($pdf.PageCount)"
foreach ($index in @(0,1,2,($pdf.PageCount-1))) {
    $page=$pdf.GetPage($index)
    $stream=New-Object Windows.Storage.Streams.InMemoryRandomAccessStream
    $operation=$page.RenderToStreamAsync($stream)
    $task=([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object { $_.Name -eq 'AsTask' -and -not $_.IsGenericMethod -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncAction' } | Select-Object -First 1).Invoke($null,@($operation))
    $task.Wait()
    $reader=[System.IO.WindowsRuntimeStreamExtensions]::AsStreamForRead($stream)
    $output=[System.IO.File]::Create((Join-Path $OutputDirectory "page-$index.png"))
    $reader.CopyTo($output)
    $output.Dispose(); $reader.Dispose(); $page.Dispose()
}
