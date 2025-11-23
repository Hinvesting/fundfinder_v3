# Stripe Payment Integration Guide

## Overview
FundFinder v3 now includes Stripe payment integration that works seamlessly with the user authentication system. Users can upgrade to a "Pro" subscription to unlock premium features.

## Setup Instructions

### 1. Get Stripe API Keys
1. Go to [Stripe Dashboard](https://dashboard.stripe.com/)
2. Navigate to Developers → API keys
3. Copy your **Secret key** (starts with `sk_test_` or `sk_live_`)
4. Copy your **Publishable key** (starts with `pk_test_` or `pk_live_`)

### 2. Update .env File
Add your Stripe keys to the `.env` file:
```env
STRIPE_SECRET_KEY=sk_test_your_secret_key_here
STRIPE_PUBLIC_KEY=pk_test_your_publishable_key_here
APP_URL=http://localhost:8000
```

### 3. Database Changes
The `users` table now includes a `subscription_status` column:
- **Default value**: `'free'`
- **After payment**: `'active'`

The database schema is automatically updated when you run the application.

## Payment Flow

### Step 1: User Authentication
User must be logged in to access payment features:
```javascript
// Check if user is logged in
const response = await fetch('/api/me');
const data = await response.json();

if (data.authenticated) {
  console.log('User subscription:', data.user.subscription_status);
}
```

### Step 2: Create Checkout Session
Frontend initiates payment by calling `/api/checkout`:
```javascript
async function upgradeToPro() {
  const response = await fetch('/api/checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' }
  });
  
  const data = await response.json();
  
  if (data.sessionId) {
    // Redirect to Stripe Checkout
    const stripe = Stripe('pk_test_your_publishable_key');
    stripe.redirectToCheckout({ sessionId: data.sessionId });
  }
}
```

### Step 3: User Completes Payment
- User is redirected to Stripe's secure checkout page
- User enters payment details
- Stripe processes payment

### Step 4: Success Redirect
After successful payment, user is redirected to:
```
http://localhost:8000/payment-success.html?session_id={CHECKOUT_SESSION_ID}
```

### Step 5: Verify Payment
Frontend calls `/api/payment-success` to activate subscription:
```javascript
async function verifyPayment(sessionId) {
  const response = await fetch('/api/payment-success', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ session_id: sessionId })
  });
  
  const data = await response.json();
  
  if (data.success) {
    console.log('Subscription activated!');
    // Refresh user data
    window.location.href = '/dashboard.html';
  }
}
```

## API Endpoints

### POST /api/checkout
Creates a Stripe Checkout Session.

**Authentication**: Required (must be logged in)

**Request**: No body required

**Response (200)**:
```json
{
  "sessionId": "cs_test_abc123..."
}
```

**Error Responses**:
- `401`: User not authenticated
- `500`: Stripe not configured or API error

**Example**:
```javascript
const response = await fetch('/api/checkout', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' }
});
const { sessionId } = await response.json();
```

### POST /api/payment-success
Verifies payment and upgrades user to Pro.

**Authentication**: Required (must be logged in)

**Request Body**:
```json
{
  "session_id": "cs_test_abc123..."
}
```

**Response (200)**:
```json
{
  "success": true,
  "message": "Subscription activated successfully!",
  "subscription_status": "active"
}
```

**Error Responses**:
- `401`: User not authenticated
- `400`: Missing session_id or payment not completed
- `500`: Stripe or database error

**Example**:
```javascript
const urlParams = new URLSearchParams(window.location.search);
const sessionId = urlParams.get('session_id');

const response = await fetch('/api/payment-success', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ session_id: sessionId })
});
const result = await response.json();
```

### GET /api/me (Updated)
Now returns subscription_status.

**Response (200) - Authenticated**:
```json
{
  "authenticated": true,
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "subscription_status": "active"
  }
}
```

## Pricing Configuration

Current pricing is set in the code:
```php
'unit_amount' => 2900, // $29.00 in cents
```

To change the price, update this value in `/api/checkout` route in `index.php`.

## Frontend Integration

### 1. Include Stripe.js
Add to your HTML:
```html
<script src="https://js.stripe.com/v3/"></script>
```

### 2. Initialize Stripe
```javascript
const stripe = Stripe('pk_test_your_publishable_key_here');
```

### 3. Create Upgrade Button
```html
<button id="upgrade-btn">Upgrade to Pro - $29</button>

<script>
document.getElementById('upgrade-btn').addEventListener('click', async () => {
  // Create checkout session
  const response = await fetch('/api/checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' }
  });
  
  const { sessionId } = await response.json();
  
  // Redirect to Stripe
  const result = await stripe.redirectToCheckout({ sessionId });
  
  if (result.error) {
    alert(result.error.message);
  }
});
</script>
```

### 4. Create Success Page
Create `payment-success.html`:
```html
<!DOCTYPE html>
<html>
<head>
  <title>Payment Success</title>
</head>
<body>
  <h1>Processing your payment...</h1>
  
  <script>
  // Get session_id from URL
  const urlParams = new URLSearchParams(window.location.search);
  const sessionId = urlParams.get('session_id');
  
  if (sessionId) {
    // Verify payment
    fetch('/api/payment-success', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ session_id: sessionId })
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        document.body.innerHTML = '<h1>✅ Payment Successful!</h1><p>Your subscription is now active.</p>';
        setTimeout(() => {
          window.location.href = '/';
        }, 2000);
      } else {
        document.body.innerHTML = '<h1>❌ Payment Failed</h1><p>' + data.error + '</p>';
      }
    });
  }
  </script>
</body>
</html>
```

### 5. Check Subscription Status
```javascript
async function checkSubscription() {
  const response = await fetch('/api/me');
  const data = await response.json();
  
  if (data.authenticated) {
    if (data.user.subscription_status === 'active') {
      // Show Pro features
      document.getElementById('pro-features').style.display = 'block';
    } else {
      // Show upgrade button
      document.getElementById('upgrade-btn').style.display = 'block';
    }
  }
}
```

## Testing with Stripe Test Mode

### Test Card Numbers
Use these cards in test mode:

**Success**:
- Card: `4242 4242 4242 4242`
- Expiry: Any future date
- CVC: Any 3 digits
- ZIP: Any 5 digits

**Decline**:
- Card: `4000 0000 0000 0002`

### Testing Flow
1. Register a test user
2. Login
3. Click upgrade button
4. Use test card `4242 4242 4242 4242`
5. Complete payment
6. Verify subscription status is "active"

## Database Schema Update

### users table (Updated)
```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    subscription_status TEXT DEFAULT 'free',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

The `subscription_status` column can have these values:
- `'free'` - Default for all new users
- `'active'` - User has paid and has Pro access

## Security Features

1. **Authentication Required**: Both payment endpoints require login
2. **User Linking**: Payment is linked to user via `client_reference_id`
3. **Stripe Verification**: Payment status is verified with Stripe before upgrading
4. **Secure Keys**: API keys stored in `.env` file (not in git)

## Common Issues & Solutions

### "Stripe not configured"
- Ensure `STRIPE_SECRET_KEY` is set in `.env`
- Verify the key starts with `sk_test_` or `sk_live_`

### "Payment not completed"
- User may have closed the checkout page
- Payment may have been declined
- Check Stripe dashboard for details

### Subscription not activated
- Ensure `/api/payment-success` is called after redirect
- Check that `session_id` is passed correctly
- Verify database connection is working

### Testing in Production
When going live:
1. Replace test keys with live keys in `.env`
2. Update `APP_URL` to your production domain
3. Test with real payment (small amount)
4. Monitor Stripe dashboard for issues

## Webhook Integration (Future Enhancement)

For production, consider adding Stripe webhooks to handle:
- Payment failures
- Subscription renewals (if switching to recurring)
- Refunds
- Chargebacks

Webhook endpoint structure:
```php
if ($uri === '/api/stripe-webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
    $webhook_secret = $_ENV['STRIPE_WEBHOOK_SECRET'];
    
    try {
        $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhook_secret);
        // Handle event types
    } catch (\Exception $e) {
        http_response_code(400);
        exit;
    }
}
```

## Quick Reference

### Environment Variables
```env
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLIC_KEY=pk_test_...
APP_URL=http://localhost:8000
```

### Subscription Statuses
- `free` - Default, limited features
- `active` - Paid, full access

### Price
- $29.00 one-time payment
- Stored as `2900` cents in code

---

**Last Updated**: November 23, 2025
**Version**: 3.2 (with Stripe Integration)
