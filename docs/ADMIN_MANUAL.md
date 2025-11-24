# FundFinder v3 - Administrator Manual

## Table of Contents
1. [Installation](#installation)
2. [Environment Variables](#environment-variables)
3. [Database Schema](#database-schema)
4. [API Reference](#api-reference)
5. [Testing Commands](#testing-commands)
6. [Monitoring & Maintenance](#monitoring--maintenance)

---

## Installation

### Prerequisites
- **PHP**: 8.0 or higher
- **MySQL**: 5.7 or higher
- **Composer**: Latest version
- **Git**: For repository management

### Step 1: Clone Repository
```bash
git clone https://github.com/Hinvesting/fundfinder_v3.git
cd fundfinder_v3
```

### Step 2: Install Dependencies
```bash
composer install
```

This will install:
- `vlucas/phpdotenv` - Environment configuration
- `google/apiclient` - Gemini API integration
- `stripe/stripe-php` - Payment processing

### Step 3: Configure Environment
Create a `.env` file in the root directory:
```bash
cp .env.example .env
# Or create manually with the content below
```

### Step 4: Set Up Database
Ensure MySQL is running, then either:

**Option A - Automatic** (recommended):
The database tables are created automatically when you first run the application.

**Option B - Manual**:
```bash
mysql -u root -p < setup_database.sql
```

### Step 5: Start Development Server
```bash
php -S localhost:8000
```

Visit `http://localhost:8000` in your browser.

---

## Environment Variables

### Required Configuration

Create a `.env` file with the following variables:

```env
# Google Gemini API Configuration
GEMINI_API_KEY=your_gemini_api_key_here

# Application Configuration
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database Configuration
DB_HOST=localhost
DB_NAME=fundfinder
DB_USER=root
DB_PASS=your_database_password

# Stripe Configuration
STRIPE_SECRET_KEY=sk_test_your_stripe_secret_key
STRIPE_PUBLIC_KEY=pk_test_your_stripe_public_key
```

### Getting API Keys

#### Google Gemini API Key
1. Go to [Google AI Studio](https://aistudio.google.com)
2. Sign in with your Google account
3. Navigate to "Get API key"
4. Create a new API key
5. Copy the key (starts with `AIza...`)

#### Stripe API Keys
1. Go to [Stripe Dashboard](https://dashboard.stripe.com)
2. Sign in or create an account
3. Navigate to Developers → API keys
4. Copy your **Test** keys for development:
   - Secret key (starts with `sk_test_`)
   - Publishable key (starts with `pk_test_`)
5. For production, use **Live** keys

### Environment-Specific Settings

**Development**:
```env
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000
STRIPE_SECRET_KEY=sk_test_...
```

**Production**:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
STRIPE_SECRET_KEY=sk_live_...
```

---

## Database Schema

### Overview
FundFinder uses MySQL with 4 main tables:
- `users` - User accounts and subscriptions
- `saved_items` - User's saved funding opportunities
- `usage_logs` - Rate limiting and usage tracking

### Table: users

```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    subscription_status TEXT DEFAULT 'free',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
);
```

**Fields**:
- `id`: Auto-increment primary key
- `name`: User's full name
- `email`: Unique email address (used for login)
- `password`: BCrypt hashed password
- `subscription_status`: `'free'` or `'active'` (Pro)
- `created_at`: Account creation timestamp

### Table: saved_items

```sql
CREATE TABLE saved_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL,
    amount VARCHAR(100),
    deadline VARCHAR(100),
    link TEXT,
    match_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
);
```

**Fields**:
- `id`: Auto-increment primary key
- `user_id`: Links to users table
- `name`: Funding opportunity name
- `type`: Grant, Loan, or Investor
- `amount`: Funding amount range
- `deadline`: Application deadline
- `link`: Application URL
- `match_reason`: Why this matches the user
- `created_at`: Save timestamp

### Table: usage_logs

```sql
CREATE TABLE usage_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    search_date TEXT NOT NULL,
    count INT DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY user_date (user_id, search_date)
);
```

**Fields**:
- `id`: Auto-increment primary key
- `user_id`: Links to users table
- `search_date`: Date in YYYY-MM-DD format
- `count`: Number of searches that day
- `UNIQUE KEY`: Prevents duplicate entries per user per day

### Database Maintenance

**View all users**:
```sql
SELECT id, name, email, subscription_status, created_at FROM users;
```

**Check today's usage**:
```sql
SELECT u.name, u.email, ul.count 
FROM users u
JOIN usage_logs ul ON u.id = ul.user_id
WHERE ul.search_date = CURDATE();
```

**Clear old usage logs** (older than 30 days):
```sql
DELETE FROM usage_logs 
WHERE search_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY);
```

**Upgrade user to Pro**:
```sql
UPDATE users SET subscription_status = 'active' WHERE email = 'user@example.com';
```

---

## API Reference

### Base URL
- Development: `http://localhost:8000`
- Production: `https://yourdomain.com`

### Authentication
Most endpoints require user authentication via PHP sessions. Sessions are established after login/register and maintained via cookies.

---

### Authentication Endpoints

#### POST /api/register
Create a new user account.

**Request**:
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "securepassword123"
}
```

**Success Response (200)**:
```json
{
  "success": true,
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

**Error Responses**:
- `400`: Missing required fields
- `409`: Email already registered
- `500`: Database error

---

#### POST /api/login
Authenticate user and create session.

**Request**:
```json
{
  "email": "john@example.com",
  "password": "securepassword123"
}
```

**Success Response (200)**:
```json
{
  "success": true,
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

**Error Responses**:
- `400`: Missing email or password
- `401`: Invalid credentials
- `500`: Database error

---

#### POST /api/logout
Destroy user session.

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

---

#### GET /api/me
Check authentication status and get user info.

**Success Response (200) - Authenticated**:
```json
{
  "authenticated": true,
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "subscription_status": "free",
    "daily_searches_left": 2
  }
}
```

**Success Response (200) - Not Authenticated**:
```json
{
  "authenticated": false
}
```

---

### Search Endpoint

#### POST /api/search
Search for funding opportunities using AI.

**Authentication**: Required  
**Rate Limit**: 3 searches/day (free), unlimited (Pro)

**Request**:
```json
{
  "type": "Tech Startup",
  "location": "Seattle",
  "purpose": "Equipment needs"
}
```

**Success Response (200)**:
```json
[
  {
    "name": "Seattle Tech Innovation Grant",
    "type": "Grant",
    "amount": "$10,000 - $50,000",
    "deadline": "Rolling",
    "link": "https://seattle.gov/tech-grants",
    "match_reason": "Specifically for Seattle tech startups needing equipment"
  },
  {
    "name": "Washington State Small Business Loan",
    "type": "Loan",
    "amount": "$5,000 - $100,000",
    "deadline": "Dec 31, 2025",
    "link": "https://wa.gov/business-loans",
    "match_reason": "Available for Washington businesses for equipment purchases"
  }
]
```

**Error Responses**:
- `401`: "Please login to search" - User not authenticated
- `429`: "Free limit reached. Upgrade to Pro for unlimited searches" - Rate limit exceeded
- `400`: Missing required fields
- `500`: API or database error

---

### Saved Items Endpoints

#### POST /api/save
Save a funding opportunity to user's list.

**Authentication**: Required

**Request**:
```json
{
  "name": "Seattle Tech Grant",
  "type": "Grant",
  "amount": "$10,000 - $50,000",
  "deadline": "Rolling",
  "link": "https://example.com",
  "match_reason": "Perfect for Seattle tech startups"
}
```

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Item saved successfully"
}
```

**Error Responses**:
- `401`: Not authenticated
- `400`: Missing required fields
- `500`: Database error

---

#### GET /api/saved
Get user's saved funding opportunities.

**Authentication**: Required

**Success Response (200)**:
```json
{
  "items": [
    {
      "id": 1,
      "name": "Seattle Tech Grant",
      "type": "Grant",
      "amount": "$10,000 - $50,000",
      "deadline": "Rolling",
      "link": "https://example.com",
      "match_reason": "Perfect for Seattle tech startups",
      "created_at": "2025-11-24 10:30:00"
    }
  ]
}
```

**Error Responses**:
- `401`: Not authenticated
- `500`: Database error

---

### Payment Endpoints

#### POST /api/checkout
Create Stripe Checkout Session for Pro upgrade.

**Authentication**: Required

**Success Response (200)**:
```json
{
  "sessionId": "cs_test_abc123..."
}
```

**Error Responses**:
- `401`: Not authenticated
- `500`: Stripe not configured or API error

**Usage**: Redirect user to Stripe Checkout with the sessionId.

---

#### POST /api/payment-success
Verify payment and activate Pro subscription.

**Authentication**: Required

**Request**:
```json
{
  "session_id": "cs_test_abc123..."
}
```

**Success Response (200)**:
```json
{
  "success": true,
  "message": "Subscription activated successfully!",
  "subscription_status": "active"
}
```

**Error Responses**:
- `401`: Not authenticated
- `400`: Missing session_id or payment not completed
- `500`: Stripe verification failed or database error

---

## Testing Commands

### Authentication Testing

#### Register a New User
```bash
curl -X POST http://localhost:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"test@example.com","password":"password123"}' \
  -c cookies.txt
```

#### Login
```bash
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password123"}' \
  -c cookies.txt
```

#### Check Authentication Status
```bash
curl http://localhost:8000/api/me -b cookies.txt
```

#### Logout
```bash
curl -X POST http://localhost:8000/api/logout -b cookies.txt
```

---

### Search Testing

#### Perform AI Search
```bash
curl -X POST http://localhost:8000/api/search \
  -H "Content-Type: application/json" \
  -d '{"type":"Tech Startup","location":"Seattle","purpose":"Equipment needs"}' \
  -b cookies.txt
```

#### Test Rate Limiting (Make 4 searches)
```bash
for i in {1..4}; do
  echo "Search $i:"
  curl -X POST http://localhost:8000/api/search \
    -H "Content-Type: application/json" \
    -d '{"type":"Restaurant","location":"New York","purpose":"Expansion"}' \
    -b cookies.txt
  echo -e "\n---"
done
```

---

### Saved Items Testing

#### Save a Funding Opportunity
```bash
curl -X POST http://localhost:8000/api/save \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Grant",
    "type": "Grant",
    "amount": "$5,000 - $20,000",
    "deadline": "Dec 31, 2025",
    "link": "https://example.com",
    "match_reason": "Test reason"
  }' \
  -b cookies.txt
```

#### Get Saved Items
```bash
curl http://localhost:8000/api/saved -b cookies.txt
```

---

### Stripe Payment Testing

#### Create Checkout Session
```bash
curl -X POST http://localhost:8000/api/checkout \
  -H "Content-Type: application/json" \
  -b cookies.txt
```

#### Verify Payment (after completing checkout)
```bash
curl -X POST http://localhost:8000/api/payment-success \
  -H "Content-Type: application/json" \
  -d '{"session_id":"cs_test_your_session_id_here"}' \
  -b cookies.txt
```

#### Test Card Numbers (Stripe Test Mode)
- **Success**: `4242 4242 4242 4242`
- **Decline**: `4000 0000 0000 0002`
- **Requires Auth**: `4000 0025 0000 3155`
- Any future expiry date, any 3-digit CVC, any 5-digit ZIP

---

## Monitoring & Maintenance

### Cost Monitoring

#### Check Daily Usage
```sql
SELECT 
    DATE(ul.search_date) as date,
    COUNT(DISTINCT ul.user_id) as active_users,
    SUM(ul.count) as total_searches
FROM usage_logs ul
WHERE ul.search_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
GROUP BY DATE(ul.search_date)
ORDER BY date DESC;
```

#### Calculate API Costs
- Model: gemini-2.0-flash-lite-preview-02-05
- Average cost per search: ~$0.0002
- Formula: `total_searches × $0.0002`

#### Monitor Free vs Pro Users
```sql
SELECT 
    subscription_status,
    COUNT(*) as user_count
FROM users
GROUP BY subscription_status;
```

---

### User Management

#### Find Users Who Hit Rate Limit
```sql
SELECT u.name, u.email, ul.count
FROM users u
JOIN usage_logs ul ON u.id = ul.user_id
WHERE ul.search_date = CURDATE() 
  AND ul.count >= 3 
  AND u.subscription_status = 'free';
```

#### Top Users by Search Volume
```sql
SELECT u.name, u.email, SUM(ul.count) as total_searches
FROM users u
JOIN usage_logs ul ON u.id = ul.user_id
GROUP BY u.id
ORDER BY total_searches DESC
LIMIT 10;
```

---

### Performance Monitoring

#### Check Response Times
Monitor your PHP server logs for slow requests:
```bash
tail -f /var/log/php_errors.log
```

#### Database Query Performance
Enable slow query log in MySQL:
```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 2;
```

---

### Security Best Practices

1. **API Keys**:
   - Never commit `.env` to git
   - Rotate keys every 90 days
   - Use different keys for dev/prod

2. **Database**:
   - Use strong passwords
   - Limit user privileges
   - Regular backups

3. **Rate Limiting**:
   - Monitor for abuse patterns
   - Adjust limits as needed
   - Block suspicious IPs if necessary

4. **HTTPS**:
   - Always use HTTPS in production
   - Configure SSL certificates
   - Enable HSTS headers

---

### Backup & Recovery

#### Backup Database
```bash
mysqldump -u root -p fundfinder > backup_$(date +%Y%m%d).sql
```

#### Restore Database
```bash
mysql -u root -p fundfinder < backup_20251124.sql
```

#### Backup `.env` File
```bash
cp .env .env.backup
```

---

### Troubleshooting

#### "Database connection failed"
- Check MySQL is running: `systemctl status mysql`
- Verify credentials in `.env`
- Test connection: `mysql -u root -p`

#### "Stripe not configured"
- Ensure `STRIPE_SECRET_KEY` is set in `.env`
- Verify key format (starts with `sk_test_` or `sk_live_`)

#### "Invalid API Response structure"
- Check Gemini API key is valid
- Verify model name is correct
- Check API quota in Google AI Studio

#### Rate limit not working
- Verify `usage_logs` table exists
- Check system date/time is correct
- Test with SQL: `SELECT * FROM usage_logs WHERE user_id = 1`

---

## Technical Architecture

### Technology Stack
- **Backend**: PHP 8.0+
- **Database**: MySQL 5.7+
- **AI Engine**: Google Gemini 2.0 Flash-Lite
- **Payments**: Stripe API
- **Session Management**: PHP Sessions

### AI Configuration
- **Model**: gemini-2.0-flash-lite-preview-02-05
- **Temperature**: 0.2 (deterministic responses)
- **Max Tokens**: 1000
- **Response Format**: JSON

### Geographic Intelligence
The AI is trained to prioritize:
1. City-level funding opportunities first
2. County-level second
3. State-level third
4. National programs last (only if industry-specific)

### Rate Limiting Logic
- Free users: 3 searches/day (resets at midnight)
- Pro users: Unlimited
- Tracked in `usage_logs` table
- Uses `INSERT ... ON DUPLICATE KEY UPDATE` for atomic increments

---

## Version History

- **v3.3** (Nov 24, 2025): Added rate limiting
- **v3.2** (Nov 23, 2025): Added Stripe payment integration
- **v3.1** (Nov 23, 2025): Added user authentication
- **v3.0** (Nov 23, 2025): Initial release with AI search

---

## Support & Resources

- **Repository**: https://github.com/Hinvesting/fundfinder_v3
- **Google AI Studio**: https://aistudio.google.com
- **Stripe Dashboard**: https://dashboard.stripe.com
- **PHP Documentation**: https://www.php.net/docs.php

---

*Last Updated: November 24, 2025*
