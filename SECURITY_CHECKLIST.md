# Security Checklist for Authorize.Net Integration

## ✅ Pre-Deployment Checklist

### Environment Configuration
- [ ] `APP_KEY` is set in `.env` and is unique (not the default)
- [ ] `.env` file is NOT committed to version control
- [ ] `.env` is in `.gitignore`
- [ ] Production environment uses `APP_ENV=production`
- [ ] `APP_DEBUG=false` in production

### HTTPS/SSL
- [ ] Application is served over HTTPS in production
- [ ] SSL certificate is valid and up-to-date
- [ ] Force HTTPS redirect is enabled
- [ ] HSTS headers are configured

### Database Security
- [ ] Database credentials are stored in `.env`
- [ ] Database user has minimal required permissions
- [ ] Database is not accessible from public internet
- [ ] Regular database backups are configured
- [ ] Encryption keys cannot be accessed by database users

### API Security
- [ ] All Authorize.Net endpoints require authentication (`auth:sanctum`)
- [ ] CORS is properly configured (only allow trusted domains)
- [ ] Rate limiting is enabled on API routes
- [ ] API tokens expire and can be revoked
- [ ] Failed authentication attempts are logged

### Code Security
- [ ] Credentials are encrypted using Laravel's `Crypt` facade
- [ ] Credentials are NEVER logged or exposed in responses
- [ ] `api_login_id` and `transaction_key` are in model's `$hidden` array
- [ ] Authorization policies are enforced on all endpoints
- [ ] Input validation is performed on all requests

### Logging & Monitoring
- [ ] All account operations are logged (without sensitive data)
- [ ] Failed authentication attempts are logged
- [ ] Payment transactions are logged
- [ ] Log files are rotated and secured
- [ ] Monitoring/alerting is set up for suspicious activity

### Testing
- [ ] Tested connecting an account
- [ ] Tested disconnecting an account
- [ ] Tested unauthorized access attempts
- [ ] Tested with both sandbox and production environments
- [ ] Verified credentials are encrypted in database
- [ ] Verified credentials are not exposed in API responses

## 🔒 Security Features Implemented

### 1. Automatic Encryption
```php
// In AuthorizeNetAccount model
protected function apiLoginId(): Attribute
{
    return Attribute::make(
        get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
        set: fn (?string $value) => $value ? Crypt::encryptString($value) : null,
    );
}
```

### 2. Hidden Credentials
```php
protected $hidden = [
    'api_login_id',
    'transaction_key',
];
```

### 3. Location-Based Access Control
- Users can only access their own location's account
- Enforced at controller and policy levels
- Location ID verified in every request

### 4. Role-Based Authorization
- Only `location_manager`, `admin`, `super_admin` can manage accounts
- Enforced via Laravel Policies

### 5. Audit Logging
```php
Log::info('Authorize.Net account connected', [
    'location_id' => $user->location_id,
    'environment' => $request->environment,
    'user_id' => $user->id
    // Never log credentials!
]);
```

## 🚨 Security Best Practices

### DO ✅
1. **Use HTTPS everywhere** - Credentials in transit must be encrypted
2. **Rotate credentials regularly** - Encourage location managers to update keys quarterly
3. **Monitor logs** - Watch for unusual access patterns
4. **Use sandbox for testing** - Never test with production credentials
5. **Keep Laravel updated** - Security patches are important
6. **Validate all inputs** - Use FormRequest validation
7. **Use prepared statements** - Laravel's Eloquent does this automatically
8. **Limit failed attempts** - Implement rate limiting
9. **Use strong APP_KEY** - Generate with `php artisan key:generate`
10. **Regular backups** - Backup database with encrypted credentials

### DON'T ❌
1. **Never log credentials** - Not even encrypted ones
2. **Never expose credentials in API** - Keep in `$hidden` array
3. **Never commit .env file** - Contains encryption keys
4. **Never use same credentials across locations** - Each location separate
5. **Never store credit card data** - Use Authorize.Net tokenization
6. **Never disable SSL verification** - Even in development
7. **Never share API tokens** - Each user should have their own
8. **Never use default APP_KEY** - Must be unique per installation
9. **Never allow direct database access from web** - Use firewall rules
10. **Never skip authorization checks** - Always verify user permissions

## 🔐 Additional Security Recommendations

### 1. Implement Two-Factor Authentication (2FA)
Add 2FA for location managers who manage payment accounts.

### 2. IP Whitelisting
Consider restricting admin panel access to known IP addresses.

### 3. Security Headers
Add security headers in `config/cors.php` or middleware:
```php
'X-Frame-Options' => 'DENY',
'X-Content-Type-Options' => 'nosniff',
'X-XSS-Protection' => '1; mode=block',
'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
```

### 4. Regular Security Audits
- Review access logs monthly
- Check for outdated dependencies: `composer outdated`
- Run security scanners: `composer audit`

### 5. Backup Strategy
- Automated daily backups
- Encrypted backup storage
- Regular restore testing
- Off-site backup storage

### 6. Incident Response Plan
Have a plan for:
- Unauthorized access detected
- Credentials potentially compromised
- Database breach
- Payment fraud detected

## 📋 Compliance Considerations

### PCI DSS (Payment Card Industry Data Security Standard)
- ✅ We do NOT store card numbers (handled by Authorize.Net)
- ✅ We do NOT store CVV codes
- ✅ API credentials are encrypted at rest
- ✅ Access is restricted and logged
- ⚠️ Ensure network security (firewall, VPN, etc.)
- ⚠️ Regular security testing required

### GDPR (if applicable)
- Data encryption at rest and in transit
- User right to delete (cascade deletes configured)
- Audit logging of data access
- Data retention policies

## 🛠️ Security Testing Commands

```bash
# Check for security vulnerabilities in dependencies
composer audit

# Check for outdated packages
composer outdated

# Verify encryption is working
php artisan tinker
>>> $account = App\Models\AuthorizeNetAccount::first();
>>> // Should see encrypted string in database, decrypted in model

# Test authorization
# Try to access another location's account - should fail

# Check logs
tail -f storage/logs/laravel.log
```

## 📞 Security Incident Response

If you suspect a security breach:

1. **Immediate Actions:**
   - Rotate all Authorize.Net credentials
   - Revoke all API tokens
   - Change database passwords
   - Regenerate APP_KEY (will invalidate all encrypted data!)

2. **Investigation:**
   - Review access logs
   - Check database for unauthorized changes
   - Contact Authorize.Net support
   - Document everything

3. **Recovery:**
   - Restore from backup if needed
   - Re-encrypt credentials with new key
   - Notify affected locations
   - Implement additional security measures

4. **Post-Incident:**
   - Review and update security policies
   - Additional staff training
   - Enhanced monitoring
   - Security audit

## 📚 References

- [Laravel Security Best Practices](https://laravel.com/docs/security)
- [Authorize.Net Security](https://www.authorize.net/about-us/security/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PCI DSS Requirements](https://www.pcisecuritystandards.org/)

---

**Last Updated:** November 28, 2025  
**Review Frequency:** Quarterly or after any security incident
