$ErrorActionPreference = 'Stop'

$brevoSenderEmail = (Read-Host 'Brevo sender email').Trim()
$brevoSecureKey = Read-Host 'Brevo API key' -AsSecureString
$brevoKeyPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($brevoSecureKey)

try {
    $brevoPlainKey = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($brevoKeyPointer)
    $brevoHeaders = @{
        Accept = 'application/json'
        'api-key' = $brevoPlainKey
    }
    $brevoResponse = Invoke-RestMethod -Method Get -Uri 'https://api.brevo.com/v3/senders' -Headers $brevoHeaders
    $brevoSender = @($brevoResponse.senders) | Where-Object {
        [string]::Equals($_.email, $brevoSenderEmail, [StringComparison]::OrdinalIgnoreCase)
    } | Select-Object -First 1

    if ($null -eq $brevoSender) {
        Write-Output 'SENDER_FOUND=false'
        exit 1
    }

    Write-Output 'SENDER_FOUND=true'
    Write-Output ('SENDER_ACTIVE=' + [string][bool]$brevoSender.active)

    if (-not [bool]$brevoSender.active) {
        exit 1
    }

    $brevoDomain = ($brevoSenderEmail -split '@', 2)[1]
    try {
        $brevoEncodedDomain = [Uri]::EscapeDataString($brevoDomain)
        $brevoDomainResponse = Invoke-RestMethod -Method Get -Uri "https://api.brevo.com/v3/senders/domains/$brevoEncodedDomain" -Headers $brevoHeaders
        Write-Output ('DOMAIN_VERIFIED=' + [string][bool]$brevoDomainResponse.verified)
        Write-Output ('DOMAIN_AUTHENTICATED=' + [string][bool]$brevoDomainResponse.authenticated)
    } catch {
        Write-Output 'DOMAIN_STATUS=not_available'
    }
} finally {
    $brevoPlainKey = $null
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($brevoKeyPointer)
}
