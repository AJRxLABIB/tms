# TMS → AI Travel Copilot: Complete Architecture & Implementation Guide

## Executive Summary

This document transforms the existing TMS (PHP/MySQL/Bootstrap monolith) into a next-generation **AI-Powered Autonomous Travel Platform** — a 24/7 Travel Copilot that replaces manual workflows with intelligent, context-aware automation backed by Claude (Anthropic) AI.

---

## 1. Technology Stack Decision

| Layer | Current | New |
|---|---|---|
| Backend | PHP 8+ / PDO | PHP 8.2+ / PDO + Claude API |
| Database | MySQL (unchanged) | MySQL + 12 new AI tables |
| AI Engine | Rule-based scoring | Claude claude-sonnet-4-20250514 via REST |
| Frontend | Bootstrap 4 + jQuery | Custom CSS + Vanilla JS (no new dependencies) |
| Notifications | None | PHP Mailer + webhook stubs |
| Caching | None | PHP APCu / file cache for AI responses |

**Why Claude API?** The existing `chatbot.php` already calls Anthropic. We extend this pattern consistently across all AI modules.

---

## 2. New Database Tables

```sql
-- AI-generated packages (replaces fixed packages for personalized results)
CREATE TABLE tbl_ai_packages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_email VARCHAR(100),
  session_id VARCHAR(100),
  prompt_hash VARCHAR(64),           -- avoid regenerating identical requests
  package_json LONGTEXT,             -- full AI-generated package as JSON
  match_score INT DEFAULT 0,
  expires_at DATETIME,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aipkg_email (user_email),
  INDEX idx_aipkg_hash (prompt_hash)
);

-- AI trip itineraries
CREATE TABLE tbl_ai_itineraries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT,
  user_email VARCHAR(100),
  itinerary_json LONGTEXT,           -- day-by-day plan from AI
  packing_list TEXT,
  ai_version VARCHAR(20),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_itin_booking (booking_id)
);

-- AI copilot session messages (Trip Copilot chat history)
CREATE TABLE tbl_copilot_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT,
  user_email VARCHAR(100),
  role ENUM('user','assistant') NOT NULL,
  message LONGTEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cop_booking (booking_id)
);

-- AI travel memories (post-trip)
CREATE TABLE tbl_travel_memories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT,
  user_email VARCHAR(100),
  journal_text LONGTEXT,
  social_captions TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- AI sentiment analysis on reviews
CREATE TABLE tbl_review_sentiment (
  id INT AUTO_INCREMENT PRIMARY KEY,
  review_id INT,
  sentiment ENUM('positive','neutral','negative'),
  score DECIMAL(4,3),
  keywords TEXT,
  summary TEXT,
  analysed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- AI fraud flags
CREATE TABLE tbl_fraud_flags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(50),           -- 'booking', 'review', 'user'
  entity_id INT,
  reason TEXT,
  risk_score INT DEFAULT 0,
  resolved TINYINT DEFAULT 0,
  flagged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- AI analytics snapshots (cached for dashboard)
CREATE TABLE tbl_ai_analytics (
  id INT AUTO_INCREMENT PRIMARY KEY,
  metric_key VARCHAR(100),
  metric_value LONGTEXT,
  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME,
  INDEX idx_analytics_key (metric_key)
);

-- User preference profile (learned over time)
CREATE TABLE tbl_user_profile (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_email VARCHAR(100) UNIQUE,
  interests JSON,                    -- detected from behavior
  budget_range VARCHAR(50),
  preferred_climate VARCHAR(50),
  travel_style VARCHAR(50),
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Eco/sustainability scores
CREATE TABLE tbl_eco_scores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  package_id INT,
  eco_score INT DEFAULT 0,
  carbon_score INT DEFAULT 0,
  notes TEXT,
  scored_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Safety & weather alerts
CREATE TABLE tbl_safety_alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  destination VARCHAR(200),
  alert_type VARCHAR(50),
  message TEXT,
  severity ENUM('info','warning','critical'),
  active TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Group trip collaboration
CREATE TABLE tbl_group_trips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_code VARCHAR(20) UNIQUE,
  lead_email VARCHAR(100),
  destination VARCHAR(200),
  trip_json LONGTEXT,
  member_emails TEXT,
  status VARCHAR(30) DEFAULT 'planning',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

## 3. AI Module Architecture

Each AI feature is a **PHP class** in `/includes/ai/` that wraps calls to the Anthropic API.

```
includes/
  ai/
    AIClient.php          ← Base Anthropic API wrapper (rate-limit, retry, cache)
    TravelRecommender.php ← Destination + match scores
    PackageGenerator.php  ← Dynamic personalized packages
    ItineraryPlanner.php  ← Day-by-day trip plans
    TripCopilot.php       ← 24/7 companion chat (booking-aware)
    HotelAdvisor.php      ← Contextual hotel rankings
    BudgetOptimizer.php   ← Budget analysis + savings tips
    PackingAssistant.php  ← Smart packing checklist
    VirtualGuide.php      ← Per-destination Q&A
    SafetyMonitor.php     ← Alerts + emergency guidance
    GroupPlanner.php      ← Multi-person trip coordination
    SentimentAnalyser.php ← Review NLP
    FraudDetector.php     ← Anomaly detection
    MemoryGenerator.php   ← Post-trip journal & captions
    Analytics.php         ← AI business insights
```

---

## 4. Files to Create / Modify / Remove

### NEW FILES
```
includes/ai/AIClient.php
includes/ai/TravelRecommender.php
includes/ai/PackageGenerator.php
includes/ai/ItineraryPlanner.php
includes/ai/TripCopilot.php
includes/ai/BudgetOptimizer.php
includes/ai/PackingAssistant.php
includes/ai/VirtualGuide.php
includes/ai/SafetyMonitor.php
includes/ai/SentimentAnalyser.php
includes/ai/MemoryGenerator.php
includes/ai/Analytics.php
api/ai.php                          ← New AI REST API endpoint
css/ai-theme.css                    ← Premium AI-focused theme
ai-copilot.php                      ← Trip Copilot page
ai-packages.php                     ← AI Dynamic Packages
ai-planner.php                      ← AI Trip Planner
packing-assistant.php               ← Packing checklist
virtual-guide.php                   ← AI Destination Guide
group-trip.php                      ← Group trip planner
travel-memory.php                   ← Post-trip memories
admin/ai-dashboard.php              ← AI analytics admin panel
admin/ai-fraud.php                  ← Fraud monitoring
```

### MODIFIED FILES
```
includes/config.php       ← Add AI table migrations + ANTHROPIC_API_KEY
includes/chatbot.php      ← Upgrade to full Trip Copilot
includes/header.php       ← Add AI navigation items
api/index.php             ← Add AI endpoints
ai-recommend.php          ← Replace rule-engine with AI engine
budget-planner.php        ← Add AI Budget Optimizer
custom-trip.php           ← Auto-generate AI itinerary on submit
package-details.php       ← Add Virtual Guide + eco score
index.php                 ← AI-powered homepage
admin/dashboard.php       ← AI analytics integration
```

### REMOVE / DEPRECATE
```
(nothing removed — all existing pages get AI enhancement)
```

---

## 5. Environment Variables

Add to `/includes/config.php` or a `.env` file:

```php
define('ANTHROPIC_API_KEY', 'sk-ant-...');
define('ANTHROPIC_MODEL',   'claude-sonnet-4-20250514');
define('AI_CACHE_TTL',      3600);      // seconds to cache AI responses
define('AI_MAX_TOKENS',     2000);
define('AI_RATE_LIMIT',     20);        // max AI calls per user per hour
```

---

## 6. Security Model

- All AI API calls go through `AIClient.php` which enforces rate limits per user/IP
- AI responses are **never** executed as code — only rendered as HTML
- All user inputs are sanitized before being passed to AI prompts
- Prompts include system instructions that prevent prompt injection
- AI-generated content is stored in DB with TTL and can be audited

---

## 7. Implementation Strategy (Phased)

### Phase 1 — Foundation (Week 1)
- Deploy `AIClient.php` base wrapper
- Add all new DB tables via migration in `config.php`
- Upgrade `ai-recommend.php` with real Claude AI
- Upgrade chatbot to Trip Copilot

### Phase 2 — Core AI Features (Week 2-3)
- `PackageGenerator.php` → `ai-packages.php`
- `ItineraryPlanner.php` → `ai-planner.php`
- `BudgetOptimizer.php` → enhanced `budget-planner.php`
- `PackingAssistant.php` → `packing-assistant.php`

### Phase 3 — Advanced Features (Week 4)
- `VirtualGuide.php` → embedded in `package-details.php`
- `SentimentAnalyser.php` → background job on new reviews
- `MemoryGenerator.php` → `travel-memory.php`
- `Analytics.php` → `admin/ai-dashboard.php`

### Phase 4 — Intelligence Layer (Week 5-6)
- User profile learning (`tbl_user_profile`)
- Fraud detection system
- Group trip planner
- Eco scores
- Safety alerts

---

## 8. AI Prompt Design Principles

Every prompt follows this structure:

```
[SYSTEM ROLE]   — Define AI as expert travel consultant
[CONTEXT]       — User profile, history, preferences
[TASK]          — Specific request (recommend, plan, advise)
[CONSTRAINTS]   — Budget, dates, group size, format
[OUTPUT FORMAT] — JSON schema or structured markdown
```

Example (Recommendation Engine):
```
You are an expert travel consultant for TMS, Bangladesh's premium travel platform.
A user is planning a trip with this profile:
- Budget: ৳45,000 total for 2 people
- Duration: 5 days
- Travel style: Family with young children
- Climate preference: Warm beaches
- Previous trips: Sundarbans (loved), Dhaka (lived there)
- Current season: November (dry, ideal for travel)

Recommend exactly 3 destinations. For each provide:
{
  "destination": "...",
  "match_score": 0-100,
  "budget_match": 0-100,
  "weather_score": 0-100,
  "safety_score": 0-100,
  "experience_score": 0-100,
  "reasoning": "...",
  "estimated_cost": "...",
  "best_time": "...",
  "highlights": ["..."],
  "warnings": ["..."]
}
Return only valid JSON array, no markdown.
```
