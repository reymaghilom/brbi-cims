@extends('layouts.policy')

@section('title', 'Acceptable Use Policy')

@section('introduction')
    BRBI CIMS is an internal Credit Investigation Management System intended only for authorized business use. By signing in, users are expected to understand and comply with this policy.
@endsection

@section('content')
    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="authorized-use">
        <h2 id="authorized-use" class="text-lg font-bold text-brand-sidebar">1. Authorized Use Only</h2>
        <p class="mt-3 leading-7 text-text-muted">BRBI CIMS is provided only for authorized business activities. Users may access only the information and functions needed for their assigned duties. Any unauthorized use or access is prohibited.</p>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="your-account">
        <h2 id="your-account" class="text-lg font-bold text-brand-sidebar">2. Your Account</h2>
        <ul class="mt-3 list-disc space-y-2 pl-5 leading-7 text-text-muted marker:text-brand-primary">
            <li><strong class="text-text-main">One person, one account.</strong> Never share your password or sign in using another person's account.</li>
            <li>Do not allow another person to use your authenticated session.</li>
            <li>Sign out whenever you leave a shared or unattended device.</li>
            <li>Promptly report suspected loss, disclosure, or compromise of your account credentials.</li>
        </ul>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="confidentiality">
        <h2 id="confidentiality" class="text-lg font-bold text-brand-sidebar">3. Confidentiality</h2>
        <ul class="mt-3 list-disc space-y-2 pl-5 leading-7 text-text-muted marker:text-brand-primary">
            <li>Client, applicant, and co-maker information must be treated as confidential.</li>
            <li>Do not unnecessarily copy, screenshot, download, forward, photograph, or disclose protected information.</li>
            <li>Use information only for authorized credit-investigation work.</li>
            <li>Use approved internal channels whenever protected information must be shared for legitimate work.</li>
        </ul>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="accurate-records">
        <h2 id="accurate-records" class="text-lg font-bold text-brand-sidebar">4. Accurate Records</h2>
        <ul class="mt-3 list-disc space-y-2 pl-5 leading-7 text-text-muted marker:text-brand-primary">
            <li>Record only information actually obtained or verified during authorized work.</li>
            <li>Do not falsify or intentionally misrepresent investigation information.</li>
            <li>Do not fabricate dates, activities, findings, photographs, or supporting information.</li>
            <li>Make corrections only through authorized system functions. Audit history forms part of system accountability.</li>
        </ul>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="not-allowed">
        <h2 id="not-allowed" class="text-lg font-bold text-brand-sidebar">5. What Is Not Allowed</h2>
        <ul class="mt-3 grid list-disc gap-x-8 gap-y-2 pl-5 leading-7 text-text-muted marker:text-danger sm:grid-cols-2">
            <li>Accessing records outside your authorized permissions</li>
            <li>Bypassing access controls</li>
            <li>Tampering with data or audit records</li>
            <li>Unauthorized bulk extraction of information</li>
            <li>Introducing malicious scripts or software</li>
            <li>Sharing credentials</li>
            <li>Using client information for personal purposes</li>
            <li>Interfering with other users or system availability</li>
        </ul>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="monitoring-audit">
        <h2 id="monitoring-audit" class="text-lg font-bold text-brand-sidebar">6. Monitoring and Audit</h2>
        <p class="mt-3 leading-7 text-text-muted">Authenticated activity may be logged and reviewed for security, compliance, authorized investigation, troubleshooting, and accountability. This may include login activity, record changes, uploads, status changes, and other actions performed within the system.</p>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="reporting-problem">
        <h2 id="reporting-problem" class="text-lg font-bold text-brand-sidebar">7. Reporting a Problem</h2>
        <p class="mt-3 leading-7 text-text-muted">Immediately report suspected unauthorized access, incorrect exposure of information, lost or compromised credentials, suspicious system behavior, or other security incidents through your organization's authorized reporting channel.</p>
        <div class="mt-4 rounded-control border border-brand-primary/25 bg-brand-soft px-4 py-3 text-sm leading-6 text-brand-sidebar">
            Do not independently investigate a suspected breach unless you are authorized to do so.
        </div>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="consequences">
        <h2 id="consequences" class="text-lg font-bold text-brand-sidebar">8. Consequences</h2>
        <p class="mt-3 leading-7 text-text-muted">Misuse may result in proportionate action, including restriction or suspension of access, internal investigation, administrative or disciplinary action, and other measures permitted by organizational policy and applicable law.</p>
    </section>
@endsection

@section('cross-link')
    <a href="{{ route('policies.privacy') }}" class="font-semibold text-brand-primary hover:text-brand-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2">Privacy Notice</a>
@endsection
