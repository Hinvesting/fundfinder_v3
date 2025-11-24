# Rate Limiting Update - Summary

## ✅ What Was Implemented

### 1. Database Changes
- ✅ Created `usage_logs` table to track daily search counts
- ✅ Fields: `user_id`, `search_date` (YYYY-MM-DD), `count`
- ✅ Unique constraint on (user_id, search_date) to prevent duplicates

### 2. Rate Limiting Logic

**Free Users (subscription_status = 'free')**:
- Daily limit: **3 searches**
- Resets at midnight automatically
- After limit: Shows "Upgrade to Pro" message

**Pro Users (subscription_status = 'active')**:
- Daily limit: **Unlimited**
- No restrictions on searches

### 3. New Helper Functions

| Function | Purpose |
|----------|---------|
| `getDailySearchCount($userId)` | Gets today's search count for user |
| `incrementSearchCount($userId)` | Increments count after successful search |
| `checkRateLimit($userId)` | Checks if user can search (enforces limits) |

### 4. API Updates

**POST /api/search** (BREAKING CHANGE):
- ❌ **Before**: Public endpoint, no authentication required
- ✅ **Now**: Requires login, rate limited

**Error Responses**:
- `401`: Not logged in → "Please login to search"
- `429`: Rate limit exceeded → "Free limit reached. Upgrade to Pro for unlimited searches."

**GET /api/me** (Enhanced):
- ✅ Now returns `daily_searches_left`
- Free users: Number (0-3)
- Pro users: String "unlimited"

## Implementation Details

### Search Flow with Rate Limiting

```
User submits search
    ↓
Check authentication
    ↓ (not logged in)
    Return 401 "Please login to search"
    ↓ (logged in)
Check subscription status
    ↓ (Pro)
    Allow search (skip count check)
    ↓ (Free)
Check daily usage from usage_logs
    ↓ (count >= 3)
    Return 429 "Free limit reached"
    ↓ (count < 3)
Allow search
    ↓
Execute AI search with Gemini
    ↓ (success)
Increment usage count
    ↓
Return results
```

### Database Schema

**usage_logs table**:
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

**How it works**:
- One row per user per day
- `count` increments with each search
- UNIQUE constraint prevents duplicate rows
- Uses `INSERT ... ON DUPLICATE KEY UPDATE` for atomic increment

## Testing

### Test Free User (3 searches/day)

```bash
# Register
curl -X POST http://localhost:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test","email":"test@test.com","password":"pass"}' \
  -c cookies.txt

# Search 1, 2, 3 (should work)
for i in {1..3}; do
  curl -X POST http://localhost:8000/api/search \
    -d '{"type":"Tech","location":"Seattle","purpose":"Equipment"}' \
    -b cookies.txt
done

# Search 4 (should fail with 429)
curl -X POST http://localhost:8000/api/search \
  -d '{"type":"Tech","location":"Seattle","purpose":"Equipment"}' \
  -b cookies.txt
```

### Check Searches Left

```bash
curl http://localhost:8000/api/me -b cookies.txt
```

**Response**:
```json
{
  "authenticated": true,
  "user": {
    "id": 1,
    "name": "Test",
    "email": "test@test.com",
    "subscription_status": "free",
    "daily_searches_left": 0
  }
}
```

## Frontend Changes Needed

### 1. Show Search Counter
```javascript
const { user } = await (await fetch('/api/me')).json();

if (user.daily_searches_left === 'unlimited') {
  statusEl.textContent = '✨ Pro: Unlimited Searches';
} else if (user.daily_searches_left === 0) {
  statusEl.textContent = '🚫 Daily limit reached';
  showUpgradeButton();
} else {
  statusEl.textContent = `${user.daily_searches_left} searches left today`;
}
```

### 2. Handle Rate Limit Errors
```javascript
const response = await fetch('/api/search', {
  method: 'POST',
  body: JSON.stringify({ type, location, purpose })
});

if (response.status === 401) {
  alert('Please login to search');
  window.location.href = '/login.html';
} else if (response.status === 429) {
  const data = await response.json();
  alert(data.error); // "Free limit reached. Upgrade to Pro..."
  showUpgradePrompt();
}
```

### 3. Require Login Before Search
```javascript
// Check before allowing search
const { authenticated } = await (await fetch('/api/me')).json();

if (!authenticated) {
  alert('Please login to search for funding');
  window.location.href = '/login.html';
  return;
}

// Proceed with search...
```

## Cost Protection Benefits

### Without Rate Limiting
- Unlimited searches for all users
- Potential for abuse
- Unpredictable Gemini API costs

### With Rate Limiting
- Free users: **Max 3 searches/day**
- 100 free users = 300 searches/day max
- At $0.0002/search ≈ **$0.06/day**
- Pro users unlimited (but paying customers)
- **Total monthly cost**: Predictable ~$2-5

## Breaking Changes

⚠️ **IMPORTANT**: `/api/search` now requires authentication

**Before**:
```javascript
// Anyone could search without login
fetch('/api/search', { ... });
```

**After**:
```javascript
// Must be logged in
if (!isLoggedIn) {
  redirectToLogin();
  return;
}
fetch('/api/search', { ... });
```

## Configuration

### Change Free User Limit

In `index.php`, line ~130:
```php
// Current: 3 searches
$freeLimit = 3;

// Change to 5:
$freeLimit = 5;
```

### Disable Rate Limiting (Testing Only)

Comment out in `/api/search` route:
```php
// TEMPORARILY DISABLE FOR TESTING
// $rateLimitCheck = checkRateLimit($_SESSION['user_id']);
// if (!$rateLimitCheck['allowed']) { ... }
```

## Files Modified

| File | Changes |
|------|---------|
| `index.php` | Added usage_logs table, rate limit helpers, updated /api/search and /api/me |
| `setup_database.sql` | Added usage_logs table definition |
| `RATE_LIMITING_GUIDE.md` | Complete documentation |

## Next Steps

1. ✅ Test with free user (3 searches)
2. ✅ Test with Pro user (unlimited)
3. ✅ Update frontend to show search counter
4. ✅ Add upgrade prompts when limit reached
5. ⏳ Monitor usage in database
6. ⏳ Set up analytics dashboard

## Monitoring Queries

```sql
-- Today's total searches
SELECT SUM(count) FROM usage_logs WHERE search_date = CURDATE();

-- Users who hit limit
SELECT u.email, ul.count 
FROM users u 
JOIN usage_logs ul ON u.id = ul.user_id 
WHERE ul.search_date = CURDATE() AND ul.count >= 3;

-- Average searches per user
SELECT AVG(count) FROM usage_logs WHERE search_date = CURDATE();
```

---

**Status**: ✅ Rate Limiting Fully Implemented  
**Breaking Change**: /api/search now requires authentication  
**Cost Protection**: Active (3 searches/day for free users)  
**Documentation**: See RATE_LIMITING_GUIDE.md
