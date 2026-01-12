# Add-On Min/Max Quantity Implementation Guide

## Overview
This guide covers the implementation of minimum and maximum quantity limits for add-ons. This feature prevents customers from selecting unrealistic quantities (e.g., 100 pizzas) and ensures add-ons are ordered in reasonable amounts.

## Database Changes

### Migration: `add_min_max_quantity_to_add_ons_table`

**Columns Added:**
- `min_quantity` (integer, default: 1) - Minimum quantity that can be ordered
- `max_quantity` (integer, nullable) - Maximum quantity that can be ordered (null = unlimited)

**Location:** After `is_active` column in `add_ons` table

```bash
php artisan migrate
```

## Model Updates

### AddOn Model (`app/Models/AddOn.php`)

**New Fillable Fields:**
```php
'min_quantity',
'max_quantity',
```

**New Casts:**
```php
'min_quantity' => 'integer',
'max_quantity' => 'integer',
```

## API Endpoints

### Create Add-On
**POST** `/api/addons`

**Request Body:**
```json
{
  "name": "Pizza",
  "price": 15.99,
  "description": "Large cheese pizza",
  "location_id": 1,
  "is_active": true,
  "min_quantity": 1,
  "max_quantity": 10
}
```

**Validation Rules:**
- `min_quantity`: Optional, integer, minimum value of 1
- `max_quantity`: Optional, nullable, integer, minimum value of 1, must be >= min_quantity

### Update Add-On
**PUT** `/api/addons/{id}`

**Request Body:**
```json
{
  "name": "Pizza",
  "min_quantity": 2,
  "max_quantity": 8
}
```

**Validation Rules:** Same as create

## Frontend Integration

### Display Add-On Quantity Limits

```javascript
// Example add-on with limits
{
  "id": 1,
  "name": "Pizza",
  "price": "15.99",
  "min_quantity": 1,
  "max_quantity": 10,
  "is_active": true
}
```

### Quantity Selector Implementation

```javascript
// React/Vue/Angular example
<select name="quantity">
  {Array.from(
    { length: (addon.max_quantity || 100) - addon.min_quantity + 1 }, 
    (_, i) => addon.min_quantity + i
  ).map(qty => (
    <option value={qty}>{qty}</option>
  ))}
</select>
```

### Validation on Frontend

```javascript
function validateQuantity(quantity, addon) {
  if (quantity < addon.min_quantity) {
    return `Minimum quantity is ${addon.min_quantity}`;
  }
  
  if (addon.max_quantity && quantity > addon.max_quantity) {
    return `Maximum quantity is ${addon.max_quantity}`;
  }
  
  return null; // Valid
}
```

## Backend Validation (Booking Process)

When creating a booking with add-ons, validate quantities:

```php
// In BookingController or similar
foreach ($request->add_ons as $addOnData) {
    $addOn = AddOn::findOrFail($addOnData['id']);
    $quantity = $addOnData['quantity'];
    
    // Validate minimum
    if ($quantity < $addOn->min_quantity) {
        return response()->json([
            'success' => false,
            'message' => "Quantity for {$addOn->name} must be at least {$addOn->min_quantity}"
        ], 422);
    }
    
    // Validate maximum (if set)
    if ($addOn->max_quantity && $quantity > $addOn->max_quantity) {
        return response()->json([
            'success' => false,
            'message' => "Quantity for {$addOn->name} cannot exceed {$addOn->max_quantity}"
        ], 422);
    }
}
```

## Common Use Cases

### 1. Pizza Orders
```json
{
  "name": "Large Pizza",
  "min_quantity": 1,
  "max_quantity": 10,
  "price": "15.99"
}
```
**Reason:** Prevents unrealistic orders (e.g., 100 pizzas)

### 2. Party Favors
```json
{
  "name": "Party Favor Bags",
  "min_quantity": 5,
  "max_quantity": 50,
  "price": "2.99"
}
```
**Reason:** Sold in minimum packs of 5, maximum practical limit of 50

### 3. Unlimited Add-Ons (Arcade Tokens)
```json
{
  "name": "Arcade Tokens",
  "min_quantity": 10,
  "max_quantity": null,
  "price": "0.25"
}
```
**Reason:** Minimum purchase of 10 tokens, no maximum limit

### 4. Single-Item Only (Reserved Table)
```json
{
  "name": "Reserved VIP Table",
  "min_quantity": 1,
  "max_quantity": 1,
  "price": "50.00"
}
```
**Reason:** Can only book one VIP table per booking

## Default Values

When creating a new add-on without specifying quantities:
- `min_quantity` defaults to `1`
- `max_quantity` defaults to `null` (unlimited)

## Migration Rollback

To rollback this feature:

```bash
php artisan migrate:rollback --step=1
```

This will remove the `min_quantity` and `max_quantity` columns from the `add_ons` table.

## Testing

### Test Cases

1. **Create add-on with valid min/max**
   - min_quantity = 1, max_quantity = 10
   - Expected: Success

2. **Create add-on with invalid max < min**
   - min_quantity = 5, max_quantity = 3
   - Expected: Validation error

3. **Create add-on with unlimited quantity**
   - min_quantity = 1, max_quantity = null
   - Expected: Success

4. **Update add-on quantities**
   - Update existing add-on with new limits
   - Expected: Success with updated values

5. **Booking with invalid quantity**
   - Add-on has max_quantity = 10, try to book 15
   - Expected: Validation error

## API Response Examples

### Success Response (Create/Update)
```json
{
  "success": true,
  "message": "Add-on created successfully",
  "data": {
    "id": 1,
    "name": "Pizza",
    "price": "15.99",
    "min_quantity": 1,
    "max_quantity": 10,
    "is_active": true,
    "location_id": 1,
    "created_at": "2026-01-12T21:30:00.000000Z",
    "updated_at": "2026-01-12T21:30:00.000000Z"
  }
}
```

### Validation Error (max < min)
```json
{
  "message": "The max quantity field must be greater than or equal to min quantity.",
  "errors": {
    "max_quantity": [
      "The max quantity field must be greater than or equal to min quantity."
    ]
  }
}
```

## Notes

- `max_quantity` is nullable - null means unlimited
- `min_quantity` cannot be null and must be at least 1
- Frontend should display quantity limits to users
- Backend must validate quantities during booking creation
- Consider adding these limits to the checkout UI to prevent confusion

## Support

For questions or issues, refer to the main project documentation or contact the development team.
