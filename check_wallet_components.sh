#!/bin/bash

echo "Checking wallet conversion components..."
echo ""

# Check enums
echo "=== ENUMS ==="
[ -f app/Enums/WalletType.php ] && echo "✓ WalletType.php" || echo "✗ WalletType.php"
grep -q "case Win" app/Enums/WalletType.php 2>/dev/null && echo "  ✓ Win case" || echo "  ✗ Win case missing"
[ -f app/Enums/WalletConversionDirection.php ] && echo "✓ WalletConversionDirection.php" || echo "✗ WalletConversionDirection.php"
[ -f app/Enums/WalletConversionStatus.php ] && echo "✓ WalletConversionStatus.php" || echo "✗ WalletConversionStatus.php"

echo ""
echo "=== MODELS ==="
[ -f app/Models/WalletConversion.php ] && echo "✓ WalletConversion.php" || echo "✗ WalletConversion.php"
[ -f app/Models/ConversionQuote.php ] && echo "✓ ConversionQuote.php" || echo "✗ ConversionQuote.php"
[ -f app/Models/LedgerEntry.php ] && echo "✓ LedgerEntry.php" || echo "✗ LedgerEntry.php"

echo ""
echo "=== MIGRATIONS ==="
ls database/migrations/*wallet_conversion* 2>/dev/null && echo "✓ wallet_conversions migration" || echo "✗ wallet_conversions migration"
ls database/migrations/*conversion_quote* 2>/dev/null && echo "✓ conversion_quotes migration" || echo "✗ conversion_quotes migration"

echo ""
echo "=== CONTROLLERS ==="
[ -f app/Http/Controllers/Api/V1/WalletConversionController.php ] && echo "✓ WalletConversionController.php" || echo "✗ WalletConversionController.php"

echo ""
echo "=== SERVICES ==="
[ -f app/Services/Wallet/Conversion/WalletConversionService.php ] && echo "✓ WalletConversionService.php" || echo "✗ WalletConversionService.php"
[ -f app/Services/Wallet/Conversion/ConversionQuoteService.php ] && echo "✓ ConversionQuoteService.php" || echo "✗ ConversionQuoteService.php"
[ -f app/Services/Wallet/Conversion/ConversionFeeCalculator.php ] && echo "✓ ConversionFeeCalculator.php" || echo "✗ ConversionFeeCalculator.php"

echo ""
echo "=== CONTRACTS ==="
[ -f app/Contracts/Wallet/WalletConversionServiceContract.php ] && echo "✓ WalletConversionServiceContract.php" || echo "✗ WalletConversionServiceContract.php"

echo ""
echo "=== PROVIDER BINDING ==="
grep -q "WalletConversionServiceContract" app/Providers/AppServiceProvider.php && echo "✓ Binding in AppServiceProvider" || echo "✗ Binding missing"

