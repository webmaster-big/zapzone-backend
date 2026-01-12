# Add-On Min/Max Quantity - Quick Reference

## Quick Setup

1. **Run Migration**
   ```bash
   php artisan migrate
   ```

2. **API Usage**
   - Create/update add-ons with `min_quantity` and `max_quantity` fields
   - Both are optional in requests
   - Defaults: `min_quantity = 1`, `max_quantity = null` (unlimited)

## API Fields

| Field | Type | Required | Default | Validation |
|-------|------|----------|---------|------------|
| `min_quantity` | integer | No | 1 | min: 1 |
| `max_quantity` | integer/null | No | null | min: 1, >= min_quantity |

## Example Requests

### Create Add-On with Limits
```json
POST /api/addons
{
  "name": "Pizza",
  "price": 15.99,
  "min_quantity": 1,
  "max_quantity": 10
}
```

### Unlimited Quantity
```json
{
  "name": "Arcade Tokens",
  "min_quantity": 10,
  "max_quantity": null
}
```

### Single Item Only
```json
{
  "name": "VIP Table",
  "min_quantity": 1,
  "max_quantity": 1
}
```

## Frontend Validation

```javascript
if (quantity < addon.min_quantity || 
    (addon.max_quantity && quantity > addon.max_quantity)) {
  // Show error
}
```

## Common Limits

- **Food items**: min: 1, max: 10-20
- **Party supplies**: min: 5-10, max: 50-100
- **Tokens/Credits**: min: 10, max: null (unlimited)
- **Reserved spaces**: min: 1, max: 1

## Validation Messages

- "Minimum quantity is {min}"
- "Maximum quantity is {max}"
- "Max quantity must be >= min quantity"

## Files Changed

- Migration: `database/migrations/2026_01_12_211804_add_min_max_quantity_to_add_ons_table.php`
- Model: `app/Models/AddOn.php`
- Controller: `app/Http/Controllers/Api/AddOnController.php`
- Docs: `ADDON_MIN_MAX_QUANTITY.md`
