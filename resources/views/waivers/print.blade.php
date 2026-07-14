<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Waiver #{{ $waiver->id }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 11px; line-height: 1.5; margin: 0; padding: 28px 32px; }

        .header { border-bottom: 2px solid #1d4ed8; padding-bottom: 12px; margin-bottom: 18px; }
        .header td { vertical-align: top; }
        .header .doc-title { font-size: 19px; font-weight: bold; margin: 0 0 3px; color: #111827; }
        .header .doc-sub { color: #6b7280; font-size: 11px; }
        .header .ref { text-align: right; color: #6b7280; font-size: 10px; }
        .header .ref .wid { font-size: 13px; font-weight: bold; color: #111827; }
        .status { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: .03em; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-expired { background: #fee2e2; color: #991b1b; }
        .status-voided { background: #e5e7eb; color: #4b5563; }

        .section-title { font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: .05em;
            color: #1d4ed8; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; margin: 20px 0 10px; }

        table.info { width: 100%; border-collapse: collapse; }
        table.info td { padding: 3px 0; vertical-align: top; }
        table.info td.label { width: 22%; color: #6b7280; padding-right: 10px; }
        table.info td.value { width: 28%; color: #111827; font-weight: bold; padding-right: 16px; }

        .body-copy { white-space: normal; }
        .body-copy p { margin: 0 0 8px; }

        table.minors { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.minors th, table.minors td { border: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; }
        table.minors th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; color: #6b7280; letter-spacing: .03em; }

        .ack { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px; padding: 4px 12px; }
        table.ack-rows { width: 100%; border-collapse: collapse; }
        table.ack-rows td { padding: 5px 0; border-bottom: 1px solid #eef2f7; }
        table.ack-rows tr:last-child td { border-bottom: none; }
        table.ack-rows td.k { color: #4b5563; }
        table.ack-rows td.v { text-align: right; }
        .pill { display: inline-block; padding: 1px 8px; border-radius: 4px; font-size: 10px; font-weight: bold; }
        .pill-yes { background: #dcfce7; color: #166534; }
        .pill-no { background: #f3f4f6; color: #6b7280; }

        .signature { margin-top: 12px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .signature .sig-label { font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #9ca3af; }
        .signature .sig-name { font-family: Georgia, 'Times New Roman', serif; font-size: 17px; color: #111827; margin: 2px 0; }
        .signature .sig-meta { color: #6b7280; font-size: 10px; }

        .footer { margin-top: 26px; padding-top: 8px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 9px; }
    </style>
</head>
<body>
    <table class="header" width="100%">
        <tr>
            <td>
                <div class="doc-title">{{ $waiver->template?->title ?? 'Liability Waiver' }}</div>
                <div class="doc-sub">{{ $waiver->company?->name }}@if($waiver->location?->name) &middot; {{ $waiver->location->name }}@endif</div>
            </td>
            <td class="ref">
                <div class="wid">Waiver #{{ $waiver->id }}</div>
                <span class="status status-{{ $waiver->status }}">{{ ucfirst($waiver->status) }}</span>
            </td>
        </tr>
    </table>

    <div class="section-title">Participant / Guardian</div>
    <table class="info">
        <tr>
            <td class="label">Full name</td><td class="value">{{ $waiver->adult_full_name ?: '—' }}</td>
            <td class="label">Date of birth</td><td class="value">{{ optional($waiver->adult_dob)->format('F j, Y') ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Email</td><td class="value">{{ $waiver->adult_email ?: '—' }}</td>
            <td class="label">Phone</td><td class="value">{{ $waiver->adult_phone ?: '—' }}</td>
        </tr>
    </table>

    <div class="section-title">Visit Details</div>
    <table class="info">
        <tr>
            <td class="label">Location</td><td class="value">{{ $waiver->location?->name ?: '—' }}</td>
            <td class="label">Visit date</td><td class="value">{{ optional($waiver->selected_date)->format('F j, Y') ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Source</td><td class="value">{{ str_replace('_', ' ', ucfirst($waiver->source)) }}</td>
            <td class="label">Submitted</td><td class="value">{{ $waiver->submitted_at ? $waiver->submitted_at->timezone('America/Detroit')->format('F j, Y, g:i A') . ' ET' : '—' }}</td>
        </tr>
        <tr>
            <td class="label">Version</td><td class="value">v{{ $waiver->version?->version ?? $waiver->waiver_template_version_id }}</td>
            <td class="label"></td><td class="value"></td>
        </tr>
    </table>

    <div class="section-title">Waiver Agreement</div>
    <div class="body-copy">{!! nl2br(e($renderedBody)) !!}</div>

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

    <div class="section-title">Acknowledgment &amp; Signature</div>
    <div class="ack">
        <table class="ack-rows">
            <tr>
                <td class="k">Agreement accepted</td>
                <td class="v"><span class="pill {{ $waiver->agreement_accepted ? 'pill-yes' : 'pill-no' }}">{{ $waiver->agreement_accepted ? 'YES' : 'NO' }}</span></td>
            </tr>
            <tr>
                <td class="k">Electronic consent</td>
                <td class="v"><span class="pill {{ $waiver->electronic_consent_accepted ? 'pill-yes' : 'pill-no' }}">{{ $waiver->electronic_consent_accepted ? 'YES' : 'NO' }}</span></td>
            </tr>
            @if (!is_null($waiver->photo_video_consent))
            <tr>
                <td class="k">Photo / video release</td>
                <td class="v"><span class="pill {{ $waiver->photo_video_consent ? 'pill-yes' : 'pill-no' }}">{{ $waiver->photo_video_consent ? 'AGREED' : 'DECLINED' }}</span></td>
            </tr>
            @endif
            <tr>
                <td class="k">Marketing consent</td>
                <td class="v"><strong>{{ str_replace('_', ' ', ucfirst($waiver->marketing_consent_status)) }}</strong></td>
            </tr>
        </table>
        <div class="signature">
            <div class="sig-label">Signed electronically by</div>
            <div class="sig-name">{{ $waiver->typed_legal_name ?: '—' }}</div>
            <div class="sig-meta">{{ $waiver->submitted_at ? $waiver->submitted_at->timezone('America/Detroit')->format('F j, Y, g:i A') . ' ET' : 'Not yet submitted' }}</div>
        </div>
    </div>

    <div class="footer">
        Electronic record &middot; Waiver #{{ $waiver->id }} &middot; Generated {{ now()->timezone('America/Detroit')->format('F j, Y, g:i A') }} ET.
        This document reflects the waiver version the participant agreed to electronically.
    </div>
</body>
</html>
