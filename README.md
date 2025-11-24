# FundFinder v3 🚀

**AI-Powered Funding Search for Small Businesses & Startups**

FundFinder uses Google Gemini AI to find hyper-local grants, loans, and investors based on your location and business type. It features user accounts, saving capabilities, and Stripe subscription payments.

---

## 📂 Documentation

### [Admin & Developer Manual](docs/ADMIN_MANUAL.md)
**For Developers**: Installation, Database Schema, API Endpoints, and Testing.

### [User Guide](docs/USER_GUIDE.md)
**For Users**: How to search, save items, and upgrade to Pro.

---

## ⚡ Quick Start

### Install Dependencies
```bash
composer install
```

### Configure Environment
Copy `.env.example` to `.env` and add your API Keys (Gemini & Stripe).

### Run Server
```bash
php -S 0.0.0.0:8000
```

---

## 🛠️ Tech Stack

- **Backend**: PHP 8.x (No framework, pure logic)
- **Database**: MySQL
- **Frontend**: HTML5, TailwindCSS, Vanilla JS
- **AI**: Google Gemini Flash-Lite
- **Payments**: Stripe

---

**Built by Hinvesting Team**
