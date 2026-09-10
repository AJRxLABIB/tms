TMS — PROFESSIONAL PRODUCTION-GRADE TRANSFORMATION MASTER PROMPT
ROLE
Act as a senior software architect, full-stack engineer, product designer, UX specialist, database architect, cybersecurity engineer, QA engineer, DevOps engineer, AI engineer, travel-industry domain expert, and technical project manager.
You are working on an existing Travel Management System (TMS) project.
Your job is to deeply analyze the existing project first, then upgrade it into a realistic, modern, maintainable, secure, production-grade travel platform.
This is NOT a request to create a new project from scratch.
You must preserve the existing working functionality and improve the existing system incrementally.
1. ABSOLUTE RULES
Follow these rules throughout the entire task.
RULE 1 — DO NOT REWRITE THE PROJECT FROM SCRATCH
Do not unnecessarily replace the existing PHP/MySQL architecture.
First understand the current architecture, dependencies, database, APIs, pages, business logic, JavaScript, CSS, authentication, admin system, AI system, and existing features.
Modernize incrementally.
RULE 2 — DO NOT REMOVE WORKING FEATURES
Existing working functionality must remain functional unless there is a documented technical reason to replace it.
Before modifying anything:

1. Identify the existing functionality.
2. Identify its dependencies.
3. Identify what depends on it.
4. Upgrade it safely.
5. Regression-test it.

RULE 3 — NO FAKE REAL-WORLD DATA
This is extremely important.
Never fabricate:

* hotel information
* hotel prices
* flight information
* weather
* opening hours
* phone numbers
* email addresses
* emergency numbers
* transportation schedules
* attraction information
* reviews
* ratings
* availability
* visa information
* government information
* business information
* maps
* distances
* photographs
* addresses
* package prices

If real data is required, use a reliable source/API/database.
If live data is unavailable, clearly label data as:

* Sample
* Demo
* Estimated
* Simulated

Never present fake data as real.
RULE 4 — VERIFY EXTERNAL INFORMATION
For real-world information, prefer:

1. Official government/provider source
2. Official API
3. Licensed/verified third-party API
4. Trusted database
5. Reputable secondary source
6. AI inference only when appropriate

AI must NEVER be treated as the source of truth for critical factual information.
RULE 5 — DO NOT EXPOSE SECRETS
Never expose or commit:

* API keys
* passwords
* database credentials
* SMTP passwords
* payment credentials
* AI keys
* OAuth secrets
* private tokens

Use environment variables.
Create:
`.env.example`
but never distribute the real `.env`.
If credentials are found inside the repository, identify them and recommend rotation.
RULE 6 — DO NOT ASK FOR APPROVAL BETWEEN PHASES
Do not stop after analysis and ask:
"Should I continue?"
Continue through the entire implementation automatically.
Only stop if the environment technically prevents implementation.
RULE 7 — DO NOT CREATE PLACEHOLDER FEATURES
Do not create fake buttons such as:

* "Coming Soon"
* "Click here"
* "Demo"
* "TODO"

unless absolutely unavoidable.
Every implemented feature must actually work.
RULE 8 — DO NOT BREAK THE DATABASE
Never casually delete or rename existing database columns/tables.
Use migrations.
Existing data must remain recoverable.
2. FIRST: COMPLETE PROJECT AUDIT
Before modifying the project, inspect EVERYTHING.
Analyze:
Application

* PHP files
* HTML
* CSS
* JavaScript
* AJAX
* APIs
* authentication
* sessions
* authorization
* admin panel
* user dashboard
* booking
* checkout
* payment logic
* AI
* chatbot
* maps
* weather
* flights
* notifications
* PWA
* offline functionality
* localization
* currency
* wallet
* loyalty
* coupons
* reviews
* wishlist
* packages
* destinations
* activities
* transportation
* emergency functionality
* support system
* analytics

Database
Inspect:

* every SQL file
* every table
* columns
* relationships
* foreign keys
* indexes
* constraints
* seed data
* migrations
* duplicated schema
* inconsistent schema
* unused tables
* missing indexes
* data-integrity problems

Configuration
Inspect:

* `.env`
* `.env.example`
* configuration files
* API configuration
* database configuration
* email configuration
* payment configuration
* AI configuration

Never expose secret values in your final response.
Dependencies
Inspect:

* PHP libraries
* JavaScript libraries
* Bootstrap
* jQuery
* jQuery UI
* map libraries
* AI libraries
* API SDKs
* outdated dependencies
* vulnerable dependencies

3. CREATE AN INTERNAL BASELINE
Before changes, create a baseline report containing:

* existing features
* existing pages
* existing APIs
* existing database tables
* current architecture
* external integrations
* known problems
* security issues
* UX problems
* accessibility problems
* performance problems
* technical debt
* duplicated functionality
* missing functionality

Do not merely list files.
Understand how the system works.
4. TARGET PRODUCT
Transform the project into a:
PROFESSIONAL TRAVEL MANAGEMENT / TRAVEL OPERATING PLATFORM
The final product should connect:

```text
DISCOVER
   ↓
PLAN
   ↓
COMPARE
   ↓
BOOK
   ↓
PAY
   ↓
CONFIRM
   ↓
MANAGE TRIP
   ↓
TRAVEL
   ↓
TRACK
   ↓
REVIEW
   ↓
PERSONALIZE
   ↓
PLAN NEXT TRIP

```

The system should feel like one unified platform, not a collection of unrelated pages.
5. CORE TRAVEL OS
Create or improve a central:
MY TRIP
Every confirmed trip should have:

* trip name
* destination
* dates
* travelers
* itinerary
* hotel
* flights
* transport
* activities
* reservations
* tickets
* vouchers
* documents
* weather
* maps
* expenses
* budget
* emergency information
* notifications
* AI copilot

Example:

```text
MY TRIP

Cox's Bazar
12 Oct — 15 Oct

DAY 1
Flight
Hotel check-in
Beach

DAY 2
Inani Beach
Himchari
Dinner

DAY 3
Local market
Checkout
Return

```

6. DESTINATION SYSTEM
Improve destinations into complete destination profiles.
Each destination should support:
Basic

* name
* description
* images
* coordinates
* map
* region/country

Practical

* weather
* best travel season
* timezone
* currency
* language
* local transport
* emergency information

Travel

* hotels
* packages
* activities
* restaurants where legally/data-source appropriate
* attractions
* transport

Financial

* estimated daily budget
* accommodation range
* food estimate
* transport estimate
* attraction costs

Safety

* official travel advisories
* weather warnings
* local emergency services

Accessibility

* wheelchair access
* accessible hotels
* accessible transport
* accessible attractions

All dynamic information must show source and update time where appropriate.
7. PACKAGE SYSTEM
Redesign packages into structured products.
A package should support:

```text
Package
 ├── Overview
 ├── Images
 ├── Duration
 ├── Destination
 ├── Itinerary
 ├── Inclusions
 ├── Exclusions
 ├── Activities
 ├── Accommodation
 ├── Transport
 ├── Add-ons
 ├── Pricing
 ├── Availability
 ├── Cancellation policy
 ├── Booking policy
 ├── Age policy
 ├── Accessibility
 ├── Reviews
 └── Source/provenance

```

Do not store important business data only inside large JSON blobs if it should be queryable.
8. DYNAMIC PRICING ENGINE
Build a reliable pricing engine.
Pricing must support:

* base price
* travel date
* season
* weekend
* holiday
* traveler count
* adults
* children
* seniors
* room type
* occupancy
* transport
* activities
* addons
* coupons
* loyalty discounts
* service fees
* taxes
* refunds

Use integer minor currency units for monetary calculations.
Never use floating-point arithmetic for financial calculations.
Example:

```text
Base price       ৳18,000
Transport          ৳800
Service fee        ৳500
Tax              ৳1,000
Discount         -৳1,500
-------------------------
TOTAL            ৳18,800

```

9. AVAILABILITY / INVENTORY
Implement proper inventory.
Support:

* package capacity
* room availability
* activity capacity
* transport capacity
* reserved quantity
* remaining quantity
* sold-out state
* date-specific inventory

Prevent double booking using proper database transactions/locking.
Display:

```text
12 seats remaining

```

or:

```text
Only 2 rooms remaining

```

only when supported by real inventory.
10. BOOKING ENGINE
Create a complete booking workflow:

```text
Search
 ↓
Package
 ↓
Date
 ↓
Travelers
 ↓
Room
 ↓
Transport
 ↓
Activities
 ↓
Add-ons
 ↓
Review
 ↓
Payment
 ↓
Confirmation
 ↓
Voucher
 ↓
My Trip

```

Booking must support:

* booking ID
* booking status
* traveler information
* booking items
* payment status
* invoice
* QR voucher
* cancellation
* refund
* modification
* support
* booking timeline

11. PAYMENT ARCHITECTURE
Prepare the project for real payment providers.
Support architecture for:

* cards
* local payment providers
* international payment providers
* mobile financial services where applicable

For Bangladesh, design the provider layer so integrations such as:

* SSLCommerz
* bKash
* Nagad

can be implemented without changing the core booking system.
Payment statuses:

```text
pending
authorized
paid
failed
cancelled
refunded
partially_refunded
expired

```

Implement:

* webhook verification
* idempotency
* transaction records
* refund records
* reconciliation
* payment logs

Never mark a booking as paid simply because the browser returned to a success page.
12. HOTEL SYSTEM
Create a proper hotel architecture.
Support:

* hotel
* room types
* occupancy
* rates
* availability
* amenities
* policies
* cancellation
* photos
* reviews
* map
* distance
* booking

13. ACTIVITIES MARKETPLACE
Support standalone activities:

* tours
* adventure
* museums
* cultural experiences
* boat trips
* hiking
* food experiences
* photography
* entertainment

Activities must have:

* availability
* date/time
* duration
* capacity
* price
* location
* cancellation
* requirements

14. TRANSPORT SYSTEM
Support:

* walking
* bicycle
* car
* taxi
* bus
* train
* metro
* ferry
* flight

Allow comparisons:

```text
Fastest
Cheapest
Most comfortable
Lowest estimated carbon
Best for family

```

Use provider abstractions so map/routing providers can be replaced.
15. FLIGHT SYSTEM
Improve flight functionality with:

* flight number
* airline
* origin
* destination
* departure
* arrival
* status
* terminal
* gate where reliable data exists
* delays
* cancellations

Always show:

```text
Source
Last updated

```

Never fabricate live flight information.
16. WEATHER SYSTEM
Create:

```text
WeatherService

```

with provider abstraction.
Store:

* source
* fetched_at
* expires_at
* timezone
* coordinates

Support:

* current weather
* hourly forecast
* daily forecast
* severe weather
* travel recommendations

AI can interpret weather but must not invent it.
17. TRAVEL ALERT SYSTEM
Add alerts for:

* severe weather
* cyclone
* flooding
* earthquake
* transport disruption
* airport disruption
* attraction closure
* road closure
* official travel advisory

Use authoritative sources.
AI may summarize verified alerts but must not create fake alerts.
18. VISA / ENTRY REQUIREMENT SYSTEM
Create a data-driven system for:

* nationality
* destination
* visa requirement
* passport validity
* visa duration
* required documents
* official application source
* processing information

Always display:
Verify requirements with the relevant official authority before travel.
19. AI TRAVEL COPILOT
The existing AI functionality must become context-aware.
AI should have access to verified structured context such as:

* user's trip
* booking
* itinerary
* destination
* weather
* transportation
* opening hours
* budget
* traveler preferences
* availability

Example:
User:
What should I do tomorrow?
AI should use the actual trip data and verified current information.
Do not provide generic answers when real context is available.
20. AI DATA-GROUNDING RULE
Architecture:

```text
REAL DATA
   ↓
VALIDATION
   ↓
STRUCTURED CONTEXT
   ↓
AI
   ↓
EXPLANATION / RECOMMENDATION

```

Never:

```text
AI
 ↓
invent facts

```

Critical information must come from trusted sources.
21. AI PROVIDER ABSTRACTION
Create:

```text
AIProviderInterface

```

Allow future providers such as:

* Gemini
* OpenAI
* Anthropic
* other providers

without rewriting application logic.
Track:

* model
* prompt version
* tokens
* latency
* success/failure
* estimated cost
* feature
* user
* cache status

22. AI EVALUATION
Create an AI evaluation suite.
Test:

* factual accuracy
* hallucination
* source usage
* travel safety
* package accuracy
* booking accuracy
* weather accuracy
* refusal behavior
* latency
* cost

AI must not make unsafe or fabricated travel claims.
23. PERSONALIZATION
Create a traveler preference system.
Support:

* budget
* travel style
* destination preference
* activity preference
* hotel preference
* family/couple/solo/business
* accessibility
* pace
* food preferences

Recommendations should explain:

```text
Why this is recommended

```

Example:

```text
✓ Within your budget
✓ Matches your beach preference
✓ Good weather for selected dates
✓ Highly rated
✓ Available

```

24. GROUP TRAVEL
Implement:

* group trip
* invite members
* shared itinerary
* voting
* comments
* shared expenses
* expense splitting
* roles
* permissions

Example:

```text
5 travelers
Budget: ৳75,000
Spent: ৳48,200
Remaining: ৳26,800

```

25. EXPENSE SPLITTING
Allow group expenses.
Automatically calculate who owes whom.
Never use floating point for financial calculations.
26. PRICE ALERTS
Allow users to monitor:

```text
Notify me when price <= ৳15,000

```

Track historical prices where legitimate data is available.
Notify users when the threshold is reached.
27. AVAILABILITY ALERTS
Allow:
Notify me when this package becomes available.
28. DOCUMENT WALLET
Improve the wallet for travel documents.
Possible categories:

* booking voucher
* hotel confirmation
* flight ticket
* visa
* insurance
* travel permit
* emergency contacts

Sensitive documents must have:

* secure access
* authorization
* encryption where appropriate
* expiry reminders
* audit trail
* retention/deletion controls

Do not treat sensitive documents as ordinary public uploads.
29. NOTIFICATION CENTER
Create one unified notification system.
Events:

* booking confirmation
* payment
* payment failure
* refund
* trip reminder
* flight update
* weather warning
* price drop
* availability
* document expiry
* support response

Support user preferences for channels.
30. SEARCH
Build intelligent structured search.
Example:
cheap 3-day beach trip under ৳20,000 in December
Convert natural language into structured filters:

```text
type = beach
duration = 3
budget <= 20000
month = December

```

The database must determine actual results.
AI should interpret intent, not invent results.
31. RECOMMENDATION ENGINE
Create transparent recommendation scoring.
Example:

```text
Budget fit           25%
Weather              20%
Preference match     20%
Availability         15%
Rating               10%
Distance              5%
Sustainability        5%

```

Display the reasons behind recommendations.
32. ACCESSIBILITY
Target:
WCAG 2.2 AA
Implement:

* keyboard navigation
* visible focus
* semantic HTML
* labels
* accessible forms
* proper ARIA
* contrast
* accessible error messages
* touch targets
* reduced motion
* screen reader support

Do not rely on color alone.
33. DARK MODE
Create a centralized design-token system.
Do NOT patch dark mode page-by-page.
Use variables for:

* background
* surface
* text
* secondary text
* border
* accent
* success
* warning
* danger

Ensure every page, modal, dropdown, table, chart, form, map overlay and notification works in dark mode.
34. DESIGN SYSTEM
Create reusable components:

```text
Button
Card
Modal
Toast
Badge
Tabs
Dropdown
Form
Input
Select
Table
Skeleton
EmptyState
ErrorState
LoadingState

```

All pages should use the same visual language.
35. ANIMATION
Use professional purposeful animation.
Do NOT animate every element unnecessarily.
Implement:

* hover
* transitions
* page transitions
* loading
* skeleton
* modal
* success
* map markers

Respect:

```text
prefers-reduced-motion

```

Animations must never reduce usability.
36. MULTILINGUAL SYSTEM
Create a centralized translation system.
Never hardcode user-facing English strings throughout the application.
Use translation keys:

```text
booking.book_now
booking.confirm
booking.cancel

```

Support future languages.
Ensure:

* navigation
* buttons
* forms
* validation
* notifications
* emails
* AI responses where appropriate
* database content

can be localized.
Do not translate official names incorrectly.
37. CURRENCY
Support multiple currencies.
Store money using integer minor units.
Never use floating-point calculations for money.
Currency conversion must have:

* source
* rate
* timestamp
* expiry/cache policy

38. DATABASE ARCHITECTURE
Gradually introduce clear domain separation.
Potential domains:

```text
Users
Destinations
Packages
Hotels
Activities
Transport
Flights
Bookings
Payments
Trips
Reviews
Notifications
AI
Support
Analytics
Documents

```

Add appropriate:

* foreign keys
* indexes
* unique constraints
* check constraints
* transaction boundaries

Do not remove existing data without migration.
39. DATABASE MIGRATIONS
Convert scattered SQL modifications into organized migrations.
Example:

```text
database/
  migrations/
    001_initial.sql
    002_users.sql
    003_packages.sql
    004_bookings.sql
    005_payments.sql
    ...

```

Track applied migrations.
Migrations must be:

* repeatable
* ordered
* documented
* safe

40. ARCHITECTURE
Gradually separate:

```text
Controllers
Services
Repositories
Models
Validators
Providers
Integrations
Jobs
Events
API

```

Example:

```text
BookingController
        ↓
BookingService
        ↓
AvailabilityService
        ↓
PricingService
        ↓
PaymentService
        ↓
Repository
        ↓
Database

```

Business logic should not be scattered across templates.
41. PROVIDER ABSTRACTION
Create interfaces for external services.
Examples:

```text
MapProvider
WeatherProvider
FlightProvider
PaymentProvider
AIProvider
ExchangeRateProvider
NotificationProvider

```

The application should not be tightly coupled to one provider.
42. API
Improve API architecture.
Eventually support:

```text
/api/v1/

```

with:

* authentication
* authorization
* validation
* rate limiting
* consistent errors
* pagination
* versioning
* OpenAPI documentation

Do not break existing consumers.
43. WEBHOOKS
Implement secure webhook handling for providers.
Store:

* event
* payload
* signature
* timestamp
* processing status
* retry count

Use idempotency.
Never trust client-side success messages for payments.
44. SECURITY
Perform a complete security audit against current OWASP guidance.
Check:

* authentication
* authorization
* IDOR
* SQL injection
* XSS
* CSRF
* SSRF
* path traversal
* file upload
* session security
* password security
* brute force
* rate limiting
* API abuse
* secrets
* access control
* logging
* error leakage

Every endpoint must verify authorization server-side.
Never rely on hidden buttons or frontend restrictions.
45. FILE UPLOAD SECURITY
Every upload must validate:

* MIME type
* extension
* actual file signature
* size
* filename
* storage path

Never trust:

```text
$_FILES['type']

```

alone.
Prevent:

* executable uploads
* path traversal
* arbitrary file overwrite
* remote execution
* malicious SVG where unsafe

Store uploads outside executable web paths where possible.
46. ADMIN SECURITY
Implement role-based access control.
Example:

```text
Super Admin
Booking Manager
Package Manager
Finance
Support
Content Manager
AI Manager
Operations

```

Permissions should be granular.
Every sensitive admin action should be audited.
47. AUDIT LOG
Create:

```text
audit_logs

```

Track:

* user/admin
* action
* object
* old value
* new value
* timestamp
* IP where appropriate
* request information where appropriate

Never log passwords, API keys, tokens or sensitive secrets.
48. PRIVACY
Provide:

* account deletion
* data export
* notification preferences
* marketing preferences
* personalization controls
* AI history controls
* document management

Use appropriate retention policies.
49. PERFORMANCE
Optimize:

* SQL queries
* indexes
* image sizes
* JavaScript loading
* CSS
* API calls
* caching
* pagination

Use:

* lazy loading
* responsive images
* WebP/AVIF where appropriate
* cache headers
* CDN where appropriate

Do not load unnecessary libraries on every page.
50. CACHING
Create a reusable cache layer for:

* weather
* maps
* places
* geocoding
* exchange rates
* packages
* search
* recommendations
* AI responses where safe

Implement expiration and invalidation.
51. BACKGROUND JOBS
Move long-running operations out of normal page requests.
Potential jobs:

* emails
* AI generation
* weather refresh
* price monitoring
* flight monitoring
* review analysis
* reports
* image processing
* recommendation calculation

Use queue/worker architecture where practical.
52. PWA / OFFLINE
Offline mode should prioritize:

* itinerary
* booking confirmation
* tickets
* emergency contacts
* hotel information
* flight information
* packing list
* selected documents
* previously cached trip information

Do not pretend that live services work offline.
53. SEO
Implement:

* clean URLs
* canonical URLs
* metadata
* OpenGraph
* structured data
* breadcrumbs
* destination pages
* package pages
* FAQ
* review schema where valid

Example:

```text
/destinations/coxs-bazar
/packages/coxs-bazar-3-days

```

54. CONTENT / BLOG
Improve the travel blog.
Support:

* destination guides
* travel guides
* budget guides
* seasonal guides
* safety guides
* visa guides
* itineraries
* FAQs

Each article should have:

* author
* published date
* updated date
* sources
* related destinations
* related packages

55. ADMIN OPERATIONS DASHBOARD
Create operational visibility for:

```text
Today's arrivals
Today's departures
Pending bookings
Payment failures
Refunds
Support tickets
Low inventory
Weather alerts
Flight disruptions

```

Analytics should be complemented by operational information.
56. MONITORING
Create health monitoring for:

```text
Database
AI
Weather
Maps
Payments
Email
External APIs

```

Track:

* error rate
* latency
* failures
* API usage
* AI cost
* booking failures
* payment failures

57. HEALTH ENDPOINTS
Create safe internal health checks such as:

```text
/health

```

and internal checks for:

```text
database
AI
weather
maps

```

Never expose credentials or sensitive infrastructure details.
58. TESTING
Create automated tests.
Unit
Test:

* pricing
* currency
* coupons
* refunds
* availability
* dates
* recommendation scoring

Integration
Test:

* login
* registration
* booking
* checkout
* coupon
* review
* wishlist
* APIs
* admin

Security
Test:

* CSRF
* XSS
* SQL injection
* IDOR
* upload security
* authorization
* authentication

End-to-end
Test:

```text
Register
 ↓
Login
 ↓
Search
 ↓
Package
 ↓
Book
 ↓
Checkout
 ↓
Payment
 ↓
Confirmation
 ↓
My Trip

```

Also test admin workflows.
59. CI/CD
Create a professional development pipeline.

```text
feature branch
 ↓
CI
 ↓
tests
 ↓
security checks
 ↓
develop
 ↓
staging
 ↓
production

```

Do not deploy untested changes.
60. BACKUP
Implement:

* automated database backups
* encrypted backups
* offsite backups
* retention policy
* restore testing

A backup is not considered reliable until restoration has been tested.
61. RESPONSIVE DESIGN
Test all important pages at:

```text
320px
375px
390px
414px
768px
1024px
1280px
1440px
1920px

```

Check:

* navigation
* cards
* tables
* maps
* checkout
* forms
* admin
* chat
* package pages

62. ERROR / EMPTY / LOADING STATES
Every major component must support:

```text
Loading
Success
Empty
Error
Offline

```

Use skeletons where appropriate.
Never leave blank screens.
63. REAL-LIFE DATA VALIDATION
Perform extensive research for real-world travel workflows.
Research and compare appropriate current real-world platforms and official sources.
Study concepts used by modern travel services such as:

* itinerary planning
* package booking
* hotel booking
* activities
* maps
* route planning
* flight tracking
* weather
* budgeting
* collaboration
* offline travel
* loyalty
* reviews
* travel alerts

Do NOT blindly copy another site's UI, copyrighted content or proprietary implementation.
Use research to understand best practices and user expectations.
64. SOURCE PROVENANCE
For dynamic external information, maintain:

```text
source
last_updated
fetched_at
expires_at
confidence

```

Where appropriate, show users:

```text
Source: ...
Updated: ...

```

65. PHOTO / CONTENT PROVENANCE
Images and externally sourced content must have appropriate:

* source
* license
* attribution
* alt text

Do not falsely present third-party photos as original TMS photography.
66. RECOMMENDATION TRANSPARENCY
Every major recommendation should be explainable.
Example:

```text
94% match

✓ Budget compatible
✓ Weather suitable
✓ Matches beach preference
✓ Available
✓ High rating

```

67. TRAVEL MODES
Support different experiences where practical:
Family

* child-friendly
* slower itinerary
* family rooms
* safety

Couple

* romantic experiences
* private transport
* suitable hotels

Solo

* safety
* social activities
* affordable transport

Business

* hotel
* meetings
* transport
* invoices
* expenses

Accessible

* wheelchair
* limited walking
* accessible accommodation
* accessible transport

68. PRE-TRIP / DURING-TRIP / POST-TRIP
Organize the user experience into:
BEFORE TRIP

* planning
* booking
* budget
* packing
* documents
* visa
* weather

DURING TRIP

* map
* navigation
* weather
* flight
* emergency
* expenses
* AI copilot
* reservations

AFTER TRIP

* reviews
* memories
* expenses
* achievements
* recommendations
* next-trip planning

69. POST-TRIP INTELLIGENCE
After the trip, calculate useful insights such as:

```text
Planned budget: ৳35,000
Actual spend:   ৳31,500
Saved:           ৳3,500

```

and:

* preferred activities
* travel style
* future recommendations

Do not make sensitive inferences.
70. SUSTAINABILITY
Where reliable data exists, provide:

* transport comparison
* estimated emissions
* public transport options
* eco-friendly accommodations
* lower-impact alternatives

Clearly label estimates.
71. FINAL ARCHITECTURE GOAL
Gradually move toward:

```text
Frontend
   ↓
API
   ↓
Controllers
   ↓
Services
   ↓
Repositories
   ↓
Database

External services
   ↓
Provider Interfaces
   ↓
Service Layer

AI
   ↓
Verified Context
   ↓
AI Provider
   ↓
Validated Output

```

72. IMPLEMENTATION ORDER
Use this exact priority.
PHASE 1 — AUDIT & SAFETY

* inspect everything
* identify architecture
* identify dependencies
* identify vulnerabilities
* remove secret exposure
* verify configuration
* create baseline

PHASE 2 — DATA CORRECTNESS

* verify real-world data
* remove fabricated data
* introduce provenance
* improve package data
* improve destination data

PHASE 3 — DATABASE

* normalize critical domains
* add constraints
* indexes
* migration system
* preserve existing data

PHASE 4 — BOOKING CORE

* inventory
* availability
* pricing
* travelers
* booking
* cancellation
* refund

PHASE 5 — PAYMENT

* payment abstraction
* provider integrations
* webhooks
* idempotency
* reconciliation

PHASE 6 — TRIP OS

* My Trip
* itinerary
* reservations
* documents
* weather
* maps
* flight
* expenses
* emergency
* AI copilot

PHASE 7 — EXTERNAL INTEGRATIONS

* maps
* weather
* flight
* hotels
* activities
* transport
* exchange rates
* visa
* alerts

PHASE 8 — AI

* provider abstraction
* grounding
* contextual copilot
* recommendation engine
* evaluation
* cost monitoring

PHASE 9 — UX

* design system
* responsive design
* dark mode
* multilingual
* accessibility
* purposeful animations
* loading/error/empty states

PHASE 10 — QUALITY

* unit tests
* integration tests
* E2E
* security tests
* CI/CD
* monitoring
* health checks

PHASE 11 — ADVANCED

* group travel
* price alerts
* availability alerts
* accessibility travel
* family mode
* solo mode
* business travel
* sustainability

73. DO NOT OVERENGINEER
Do not introduce technologies simply because they are popular.
If existing PHP/MySQL is sufficient, keep it.
Introduce new technologies only when they solve an actual problem.
Every architectural change must have a reason.
74. BACKWARD COMPATIBILITY
Existing users/data must continue working.
If schema changes are required:

1. create migration
2. migrate data
3. test
4. maintain compatibility
5. remove old structures only when safe

75. DOCUMENT EVERYTHING
Create/update:

```text
README.md
ARCHITECTURE.md
DATABASE.md
API.md
SECURITY.md
DEPLOYMENT.md
TESTING.md
ENVIRONMENT.md
CHANGELOG.md

```

Document:

* installation
* environment variables
* database setup
* migrations
* APIs
* external services
* payment setup
* AI setup
* deployment
* testing
* troubleshooting

Never document real secret values.
76. FINAL QUALITY GATE
Before considering the project finished, perform a complete regression test.
Verify:
User

* registration
* login
* logout
* password reset
* profile
* search
* package
* destination
* booking
* checkout
* payment
* wallet
* wishlist
* review
* coupon
* loyalty
* AI
* chatbot
* map
* weather
* flight
* notifications
* trip
* expenses
* PWA

Admin

* login
* 2FA
* dashboard
* users
* bookings
* packages
* destinations
* reviews
* coupons
* content
* analytics
* AI
* support
* audit logs
* permissions

Technical

* database
* migrations
* API
* external integrations
* security
* performance
* responsive UI
* dark mode
* localization
* accessibility
* offline mode

Nothing should be left knowingly broken.
77. IMPORTANT: DO NOT CLAIM SOMETHING WORKS WITHOUT TESTING IT
If you cannot test a feature because an external credential/service is unavailable:
Do NOT pretend it works.
Instead:

```text
Implementation status:
Implemented

Testing status:
Blocked because required external credential/API is unavailable.

Manual verification required:
...

```

Be completely honest.
78. FINAL DELIVERABLE
After completing the work, provide the complete updated working project.
The final project must be directly usable.
Do NOT give me only:

* suggestions
* pseudo-code
* partial files
* screenshots
* explanations
* a list of TODOs

I need the actual modified project.
The final deliverable must include:

```text
complete source code
database migrations
updated SQL
configuration example
documentation
tests
updated assets
updated frontend
updated backend

```

79. ZIP REQUIREMENT
Create a clean final ZIP.
Recommended:

```text
TMS/
├── app/
├── public/
├── api/
├── admin/
├── assets/
├── database/
│   └── migrations/
├── tests/
├── docs/
├── scripts/
├── .env.example
├── README.md
└── ...

```

DO NOT include:

```text
.env
.git
.git/index.lock
passwords
API keys
private credentials
temporary files
logs
cache
node_modules
unnecessary development artifacts

```

unless specifically required.
80. FINAL REPORT
After implementation, provide:
A. What was changed
Detailed list.
B. New features
Detailed list.
C. Existing features improved
Detailed list.
D. Database changes
Tables, columns, indexes, migrations.
E. Security changes
Detailed security improvements.
F. API integrations
Provider, purpose and configuration.
G. AI changes
Models/providers, grounding, evaluation, cost tracking.
H. Testing
Exactly what was tested and results.
I. Known limitations
Only genuine limitations.
J. Deployment instructions
Exact step-by-step instructions.
81. MOST IMPORTANT PRODUCT PRINCIPLE
The final system must not feel like:
"A PHP tourism website with many features."
It must feel like:
"A unified intelligent travel platform that helps a traveler discover, plan, book, pay for, manage, experience and remember an entire trip."
Prioritize:

```text
ACCURACY
↓
TRUST
↓
BOOKING
↓
PAYMENTS
↓
AVAILABILITY
↓
TRIP MANAGEMENT
↓
REAL DATA
↓
AI GROUNDING
↓
SECURITY
↓
ARCHITECTURE
↓
TESTING
↓
UX POLISH

```

Do not prioritize adding random features over fixing these foundations.
82. EXECUTE
Now begin.

1. Inspect the complete existing project.
2. Understand the current implementation.
3. Research appropriate real-world travel-platform patterns and authoritative data sources.
4. Identify all weaknesses.
5. Create an implementation plan.
6. Implement the improvements.
7. Preserve existing functionality.
8. Migrate the database safely.
9. Test everything.
10. Fix regressions.
11. Perform a final security review.
12. Perform a final UX/accessibility review.
13. Perform a final real-world data accuracy review.
14. Package the complete project.
15. Provide the final implementation report.

Do not stop after the analysis. Execute the complete upgrade.
