<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Waiver #{{ $waiver->id }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 11px; line-height: 1.5; margin: 0; padding: 28px 32px; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .muted { color: #6b7280; }
        .meta { width: 100%; border-collapse: collapse; margin: 14px 0 18px; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .meta td.label { width: 150px; color: #6b7280; }
        .section-title { font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: .04em;
            color: #1d4ed8; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; margin: 18px 0 8px; }
        .body-copy { white-space: normal; }
        .body-copy p { margin: 0 0 8px; }
        table.minors { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.minors th, table.minors td { border: 1px solid #e5e7eb; padding: 5px 8px; text-align: left; }
        table.minors th { background: #f3f4f6; font-size: 10px; text-transform: uppercase; color: #6b7280; }
        .ack { background: #f9fafb; border: 1px solid #e5e7eb; padding: 10px 12px; margin-top: 8px; }
        .pill { display: inline-block; padding: 1px 7px; border-radius: 4px; font-size: 10px; font-weight: bold; }
        .pill-yes { background: #dcfce7; color: #166534; }
        .pill-no { background: #f3f4f6; color: #6b7280; }
        .footer { margin-top: 24px; padding-top: 8px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 9px; }
    </style>
</head>
<body>
    <h1>{{ $waiver->template?->title ?? 'Liability Waiver' }}</h1>
    <div class="muted">{{ $waiver->company?->name }} &middot; {{ $waiver->location?->name }}</div>

    <table class="meta">
        <tr><td class="label">Participant / Guardian</td><td>{{ $waiver->adult_full_name }}</td></tr>
        <tr><td class="label">Email</td><td>{{ $waiver->adult_email ?: '—' }}</td></tr>
        <tr><td class="label">Phone</td><td>{{ $waiver->adult_phone ?: '—' }}</td></tr>
        <tr><td class="label">Visit date</td><td>{{ optional($waiver->selected_date)->format('F j, Y') }}</td></tr>
        <tr><td class="label">Status</td><td>{{ ucfirst($waiver->status) }}</td></tr>
        <tr><td class="label">Submitted</td><td>{{ optional($waiver->submitted_at)->format('F j, Y g:i A') ?: '—' }}</td></tr>
        <tr><td class="label">Version</td><td>v{{ $waiver->version?->version ?? $waiver->waiver_template_version_id }}</td></tr>
    </table>

    <div class="section-title">Waiver Agreement</div>
    <div class="body-copy">{!! $renderedBody !!}</div>

    @if ($waiver->minors->isNotEmpty())
        <div class="section-title">Minor Participants</div>
        <table class="minors">
            <thead><tr><th>Name</th><th>Date of birth</th><th>Relationship</th></tr></thead>
            <tbody>
            @foreach ($waiver->minors as $minor)
                <tr>
                    <td>{{ trim($minor->first_name . ' ' . $minor->last_name) }}</td>
                    <td>{{ optional($minor->date_of_birth)->format('F j, Y') ?: '—' }}</td>
                    <td>{{ $minor->relationship ?: '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="section-title">Acknowledgment</div>
    <div class="ack">
        <div>Agreement accepted:
            <span class="pill {{ $waiver->agreement_accepted ? 'pill-yes' : 'pill-no' }}">{{ $waiver->agreement_accepted ? 'YES' : 'NO' }}</span>
        </div>
        <div>Electronic consent:
            <span class="pill {{ $waiver->electronic_consent_accepted ? 'pill-yes' : 'pill-no' }}">{{ $waiver->electronic_consent_accepted ? 'YES' : 'NO' }}</span>
        </div>
        @if (!is_null($waiver->photo_video_consent))
            <div>Photo/video release:
                <span class="pill {{ $waiver->photo_video_consent ? 'pill-yes' : 'pill-no' }}">{{ $waiver->photo_video_consent ? 'AGREED' : 'DECLINED' }}</span>
            </div>
        @endif
        <div>Marketing consent: <strong>{{ str_replace('_', ' ', ucfirst($waiver->marketing_consent_status)) }}</strong></div>
        <div style="margin-top:6px;">Typed legal name: <strong>{{ $waiver->typed_legal_name }}</strong></div>
    </div>

    <div class="footer">
        Electronic record &middot; Waiver #{{ $waiver->id }} &middot; Generated {{ now()->format('F j, Y g:i A') }}.
        This document reflects the waiver version the participant agreed to electronically.
    </div>
</body>
</html>
