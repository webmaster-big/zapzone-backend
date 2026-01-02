# Email Campaign System - API Documentation

This document provides comprehensive documentation for the Email Campaign System API, which allows you to create email templates, save drafts, and send bulk emails to customers, attendants, company admins, and custom email addresses.

## Overview

The email campaign system consists of two main components:
1. **Email Templates** - Save and manage reusable email templates with variable placeholders
2. **Email Campaigns** - Send emails to multiple recipients with variable substitution

## Available Template Variables

Use these variables in your email subject and body. They will be automatically replaced with actual values when the email is sent.

### Default Variables (Available for all recipients)
| Variable | Description |
|----------|-------------|
| `{{ recipient_email }}` | The recipient's email address |
| `{{ recipient_name }}` | The recipient's full name |
| `{{ recipient_first_name }}` | The recipient's first name |
| `{{ recipient_last_name }}` | The recipient's last name |
| `{{ company_name }}` | The company name |
| `{{ company_email }}` | The company email |
| `{{ company_phone }}` | The company phone number |
| `{{ company_address }}` | The company address |
| `{{ location_name }}` | The location name |
| `{{ location_email }}` | The location email |
| `{{ location_phone }}` | The location phone number |
| `{{ location_address }}` | The location full address |
| `{{ current_date }}` | Current date (formatted as "January 3, 2026") |
| `{{ current_year }}` | Current year |

### Customer-Specific Variables
| Variable | Description |
|----------|-------------|
| `{{ customer_email }}` | Customer's email address |
| `{{ customer_name }}` | Customer's full name |
| `{{ customer_first_name }}` | Customer's first name |
| `{{ customer_last_name }}` | Customer's last name |
| `{{ customer_phone }}` | Customer's phone number |
| `{{ customer_address }}` | Customer's full address |
| `{{ customer_total_bookings }}` | Customer's total number of bookings |
| `{{ customer_total_spent }}` | Customer's total amount spent |
| `{{ customer_last_visit }}` | Customer's last visit date |

### User (Attendant/Admin) Variables
| Variable | Description |
|----------|-------------|
| `{{ user_email }}` | User's email address |
| `{{ user_name }}` | User's full name |
| `{{ user_first_name }}` | User's first name |
| `{{ user_last_name }}` | User's last name |
| `{{ user_role }}` | User's role |
| `{{ user_department }}` | User's department |
| `{{ user_position }}` | User's position |

---

## API Endpoints

### Email Templates

#### List Templates
```
GET /api/email-templates
```

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `location_id` | integer | Filter by location |
| `status` | string | Filter by status: `draft`, `active`, `archived` |
| `category` | string | Filter by category |
| `search` | string | Search by name or subject |
| `per_page` | integer | Items per page (default: 15) |

**Response:**
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "name": "Welcome Email",
        "subject": "Welcome to {{ company_name }}!",
        "body": "<p>Dear {{ recipient_name }},</p>...",
        "status": "active",
        "category": "onboarding",
        "company": { ... },
        "location": { ... },
        "creator": { ... }
      }
    ],
    "current_page": 1,
    "total": 10
  }
}
```

#### Create Template
```
POST /api/email-templates
```

**Request Body:**
```json
{
  "name": "Welcome Email",
  "subject": "Welcome to {{ company_name }}!",
  "body": "<p>Dear {{ recipient_name }},</p><p>Welcome to {{ company_name }}!</p>",
  "status": "draft",
  "category": "onboarding",
  "location_id": 1
}
```

**Response:**
```json
{
  "success": true,
  "message": "Email template created successfully",
  "data": {
    "id": 1,
    "name": "Welcome Email",
    "subject": "Welcome to {{ company_name }}!",
    "body": "<p>Dear {{ recipient_name }},</p>...",
    "status": "draft",
    ...
  }
}
```

#### Get Template
```
GET /api/email-templates/{id}
```

#### Update Template
```
PUT /api/email-templates/{id}
```

**Request Body:**
```json
{
  "name": "Updated Welcome Email",
  "subject": "Welcome to {{ company_name }}, {{ recipient_first_name }}!",
  "body": "<p>Updated body...</p>",
  "status": "active"
}
```

#### Delete Template
```
DELETE /api/email-templates/{id}
```

#### Duplicate Template
```
POST /api/email-templates/{id}/duplicate
```

Creates a copy of the template with "(Copy)" appended to the name.

#### Update Template Status
```
PATCH /api/email-templates/{id}/status
```

**Request Body:**
```json
{
  "status": "active"
}
```

#### Get Available Variables
```
GET /api/email-templates/variables
```

Returns all available variables grouped by category.

#### Preview Template
```
GET /api/email-templates/{id}/preview
```

Returns the template with sample data replacing variables.

#### Preview Custom Content
```
POST /api/email-templates/preview
```

**Request Body:**
```json
{
  "subject": "Hello {{ recipient_name }}",
  "body": "<p>Welcome to {{ company_name }}!</p>"
}
```

---

### Email Campaigns

#### List Campaigns
```
GET /api/email-campaigns
```

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `location_id` | integer | Filter by location |
| `status` | string | Filter by status: `pending`, `sending`, `completed`, `failed`, `cancelled` |
| `search` | string | Search by name or subject |
| `per_page` | integer | Items per page (default: 15) |

#### Create and Send Campaign
```
POST /api/email-campaigns
```

**Request Body:**
```json
{
  "name": "January Newsletter",
  "subject": "Happy New Year, {{ recipient_first_name }}!",
  "body": "<p>Dear {{ recipient_name }},</p><p>We wish you a happy new year from {{ company_name }}!</p>",
  "recipient_types": ["customers", "attendants", "company_admin", "custom"],
  "custom_emails": ["test@gmail.com", "another@example.com"],
  "recipient_filters": {
    "status": "active",
    "location_id": 1
  },
  "email_template_id": 1,
  "send_now": true,
  "scheduled_at": null,
  "location_id": 1
}
```

**Recipient Types:**
- `customers` - All active customers
- `attendants` - All attendants at the location
- `company_admin` - Company admins and owners
- `location_managers` - Location managers
- `custom` - Custom email addresses specified in `custom_emails`

**Response:**
```json
{
  "success": true,
  "message": "Email campaign sent successfully",
  "data": {
    "id": 1,
    "name": "January Newsletter",
    "subject": "Happy New Year, {{ recipient_first_name }}!",
    "total_recipients": 150,
    "sent_count": 148,
    "failed_count": 2,
    "status": "completed",
    ...
  }
}
```

#### Get Campaign Details
```
GET /api/email-campaigns/{id}
```

Returns campaign details with statistics and logs.

#### Cancel Campaign
```
POST /api/email-campaigns/{id}/cancel
```

Cancel a pending or sending campaign.

#### Resend Campaign
```
POST /api/email-campaigns/{id}/resend
```

**Request Body:**
```json
{
  "type": "failed"
}
```

Types: `failed` (resend only to failed recipients) or `all` (resend to all recipients)

#### Delete Campaign
```
DELETE /api/email-campaigns/{id}
```

#### Preview Recipients
```
POST /api/email-campaigns/preview-recipients
```

Preview how many recipients will receive the email before sending.

**Request Body:**
```json
{
  "recipient_types": ["customers", "attendants"],
  "recipient_filters": {
    "status": "active"
  }
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "total_recipients": 150,
    "by_type": {
      "customer": 120,
      "attendant": 30
    },
    "sample_recipients": [...]
  }
}
```

#### Send Test Email
```
POST /api/email-campaigns/send-test
```

**Request Body:**
```json
{
  "subject": "Test: {{ company_name }} Newsletter",
  "body": "<p>This is a test email for {{ recipient_name }}.</p>",
  "test_email": "your-email@example.com"
}
```

#### Get Campaign Statistics
```
GET /api/email-campaigns/statistics
```

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `location_id` | integer | Filter by location |
| `start_date` | date | Start date for date range |
| `end_date` | date | End date for date range |

**Response:**
```json
{
  "success": true,
  "data": {
    "total_campaigns": 25,
    "total_emails_sent": 5000,
    "total_emails_failed": 50,
    "success_rate": 99.01,
    "status_breakdown": {
      "completed": 20,
      "pending": 2,
      "failed": 3
    },
    "recent_campaigns": [...]
  }
}
```

---

## Frontend Integration Examples

### React/TypeScript Example

```typescript
// Types
interface EmailTemplate {
  id: number;
  name: string;
  subject: string;
  body: string;
  status: 'draft' | 'active' | 'archived';
  category?: string;
  company_id: number;
  location_id?: number;
  created_at: string;
  updated_at: string;
}

interface CreateCampaignPayload {
  name: string;
  subject: string;
  body: string;
  recipient_types: ('customers' | 'attendants' | 'company_admin' | 'location_managers' | 'custom')[];
  custom_emails?: string[];
  recipient_filters?: {
    status?: string;
    location_id?: number;
  };
  email_template_id?: number;
  send_now?: boolean;
  scheduled_at?: string;
}

// API Functions
const emailCampaignApi = {
  // Templates
  getTemplates: (params?: { status?: string; search?: string }) =>
    axios.get('/api/email-templates', { params }),
  
  createTemplate: (data: Partial<EmailTemplate>) =>
    axios.post('/api/email-templates', data),
  
  updateTemplate: (id: number, data: Partial<EmailTemplate>) =>
    axios.put(`/api/email-templates/${id}`, data),
  
  deleteTemplate: (id: number) =>
    axios.delete(`/api/email-templates/${id}`),
  
  previewTemplate: (data: { subject: string; body: string }) =>
    axios.post('/api/email-templates/preview', data),
  
  getVariables: () =>
    axios.get('/api/email-templates/variables'),
  
  // Campaigns
  getCampaigns: (params?: { status?: string; search?: string }) =>
    axios.get('/api/email-campaigns', { params }),
  
  createCampaign: (data: CreateCampaignPayload) =>
    axios.post('/api/email-campaigns', data),
  
  getCampaign: (id: number) =>
    axios.get(`/api/email-campaigns/${id}`),
  
  previewRecipients: (data: Pick<CreateCampaignPayload, 'recipient_types' | 'recipient_filters'>) =>
    axios.post('/api/email-campaigns/preview-recipients', data),
  
  sendTestEmail: (data: { subject: string; body: string; test_email: string }) =>
    axios.post('/api/email-campaigns/send-test', data),
  
  getStatistics: (params?: { start_date?: string; end_date?: string }) =>
    axios.get('/api/email-campaigns/statistics', { params }),
};
```

### Example: Create and Send Email Campaign

```typescript
const sendNewsletter = async () => {
  // First, preview recipients
  const previewResponse = await emailCampaignApi.previewRecipients({
    recipient_types: ['customers', 'custom'],
    recipient_filters: { status: 'active' },
    custom_emails: ['test@gmail.com'],
  });
  
  console.log(`Will send to ${previewResponse.data.total_recipients} recipients`);
  
  // Send test email first
  await emailCampaignApi.sendTestEmail({
    subject: 'Happy New Year from {{ company_name }}!',
    body: '<p>Dear {{ customer_name }},</p><p>Thank you for being a valued customer!</p>',
    test_email: 'admin@company.com',
  });
  
  // Send the actual campaign
  const campaignResponse = await emailCampaignApi.createCampaign({
    name: 'January 2026 Newsletter',
    subject: 'Happy New Year from {{ company_name }}!',
    body: '<p>Dear {{ customer_name }},</p><p>Thank you for being a valued customer of {{ location_name }}!</p>',
    recipient_types: ['customers', 'custom'],
    custom_emails: ['test@gmail.com', 'partner@company.com'],
    recipient_filters: { status: 'active' },
    send_now: true,
  });
  
  console.log(`Campaign sent! ${campaignResponse.data.sent_count} emails delivered.`);
};
```

---

## Template Status Workflow

1. **Draft** - Template is being edited and not ready for use
2. **Active** - Template is available for use in campaigns
3. **Archived** - Template is no longer in active use but preserved for reference

---

## Campaign Status Workflow

1. **Pending** - Campaign created but not yet sending (or scheduled)
2. **Sending** - Campaign is currently sending emails
3. **Completed** - All emails have been processed
4. **Failed** - Campaign encountered a critical error
5. **Cancelled** - Campaign was manually cancelled

---

## Best Practices

1. **Always send a test email first** before sending to all recipients
2. **Preview recipients** to verify the correct audience is selected
3. **Use meaningful template names** for easy identification
4. **Save as draft** while composing complex emails
5. **Use variables consistently** to personalize emails
6. **Monitor campaign statistics** to track email performance
