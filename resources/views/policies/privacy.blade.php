@extends('layouts.policy')

@section('title', 'Privacy Notice')

@section('introduction')
    BRBI CIMS processes personal information needed for authorized credit investigation, verification, documentation, system security, audit, and related operational purposes.
@endsection

@section('content')
    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="who-we-are">
        <h2 id="who-we-are" class="text-lg font-bold text-brand-sidebar">1. Who We Are</h2>
        <p class="mt-3 leading-7 text-text-muted">BRBI CIMS is an internal Credit Investigation Management System used by authorized personnel to support approved credit-investigation work and related operations.</p>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="information-processed">
        <h2 id="information-processed" class="text-lg font-bold text-brand-sidebar">2. Personal Information We Process</h2>
        <div class="mt-4 grid gap-6 md:grid-cols-2">
            <div>
                <h3 class="font-bold text-text-main">Account and staff information</h3>
                <ul class="mt-2 list-disc space-y-1.5 pl-5 leading-7 text-text-muted marker:text-brand-primary">
                    <li>Name, username, work email, and role</li>
                    <li>Login and session information</li>
                    <li>Profile information and system activity</li>
                </ul>
            </div>
            <div>
                <h3 class="font-bold text-text-main">Credit investigation information</h3>
                <ul class="mt-2 list-disc space-y-1.5 pl-5 leading-7 text-text-muted marker:text-brand-primary">
                    <li>Applicant and co-maker details</li>
                    <li>Residence, business, employment, livelihood, financial, or credit-related information</li>
                    <li>CIBI information and residence, business, asset, barangay, neighbor, and bank or cooperative checks</li>
                    <li>Supporting photographs, videos, documents, location or map-related information, remarks, and findings</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="processing-purpose">
        <h2 id="processing-purpose" class="text-lg font-bold text-brand-sidebar">3. Why We Process It</h2>
        <ul class="mt-3 list-disc space-y-2 pl-5 leading-7 text-text-muted marker:text-brand-primary">
            <li>Conduct and document credit investigations and verify applicant or co-maker information</li>
            <li>Support authorized credit evaluation and maintain investigation records</li>
            <li>Coordinate credit-investigation activities</li>
            <li>Maintain accountability and audit trails</li>
            <li>Protect system security</li>
            <li>Meet legitimate business, legal, regulatory, audit, and records-management requirements</li>
        </ul>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="information-access">
        <h2 id="information-access" class="text-lg font-bold text-brand-sidebar">4. Who Can See It</h2>
        <p class="mt-3 leading-7 text-text-muted">Information is available to authorized users according to their roles, system permissions, and legitimate work responsibilities. Applicant and co-maker access rules and collaboration controls remain governed by the application's existing permissions. Information is disclosed outside the organization only when properly authorized or legally required.</p>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="providers-storage">
        <h2 id="providers-storage" class="text-lg font-bold text-brand-sidebar">5. Service Providers and Storage</h2>
        <p class="mt-3 leading-7 text-text-muted">BRBI CIMS may use authorized hosting, storage, email, and infrastructure service providers to operate the system. Such providers are engaged only as needed to deliver their services and are subject to applicable organizational controls and requirements.</p>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="retention">
        <h2 id="retention" class="text-lg font-bold text-brand-sidebar">6. How Long We Keep Information</h2>
        <p class="mt-3 leading-7 text-text-muted">Information is retained only for as long as necessary for its authorized business purpose and according to approved organizational, legal, regulatory, audit, and records-retention requirements. No fixed retention period is stated here where an approved schedule has not been established or published.</p>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="protection">
        <h2 id="protection" class="text-lg font-bold text-brand-sidebar">7. How We Protect It</h2>
        <p class="mt-3 leading-7 text-text-muted">BRBI CIMS uses safeguards appropriate to its configuration and operations. These include authenticated access, role and access controls, password hashing, session-security measures, activity or audit records, and controlled access to client information. HTTPS is used in production where configured. No system can guarantee absolute security.</p>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="browser-storage">
        <h2 id="browser-storage" class="text-lg font-bold text-brand-sidebar">8. Cookies and Browser Storage</h2>
        <p class="mt-3 leading-7 text-text-muted">BRBI CIMS uses essential session and security storage needed for sign-in, secure operation, and continuity of the application. Some necessary browser storage may also support system preferences or operational state.</p>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="privacy-rights">
        <h2 id="privacy-rights" class="text-lg font-bold text-brand-sidebar">9. Your Privacy Rights</h2>
        <p class="mt-3 leading-7 text-text-muted">Individuals may have applicable rights concerning their personal information under Philippine data privacy requirements, subject to lawful and operational limitations. Depending on the circumstances, these may include:</p>
        <ul class="mt-3 grid list-disc gap-x-8 gap-y-2 pl-5 leading-7 text-text-muted marker:text-brand-primary sm:grid-cols-2">
            <li>Being informed</li>
            <li>Access to personal information</li>
            <li>Correction of inaccurate information</li>
            <li>Objection where applicable</li>
            <li>Erasure or blocking where legally applicable</li>
            <li>Data portability where applicable</li>
            <li>Available complaint and remedy processes</li>
        </ul>
        <div class="mt-4 rounded-control border border-brand-primary/25 bg-brand-soft px-4 py-3 text-sm leading-6 text-brand-sidebar">
            These rights may be limited where information must be retained or processed for legitimate, legal, regulatory, audit, or records-management purposes.
        </div>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="privacy-concerns">
        <h2 id="privacy-concerns" class="text-lg font-bold text-brand-sidebar">10. Privacy Questions and Concerns</h2>
        <p class="mt-3 leading-7 text-text-muted">For privacy-related questions or requests, please contact your organization's authorized Data Protection Officer or Privacy Office through an approved internal channel.</p>
    </section>

    <section class="rounded-panel border border-ui-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="notice-changes">
        <h2 id="notice-changes" class="text-lg font-bold text-brand-sidebar">11. Changes to This Notice</h2>
        <p class="mt-3 leading-7 text-text-muted">This Privacy Notice may be updated when the system's processing changes, operational requirements change, or applicable privacy requirements change. The current version will be identified on this page.</p>
    </section>
@endsection

@section('cross-link')
    <a href="{{ route('policies.acceptable-use') }}" class="font-semibold text-brand-primary hover:text-brand-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2">Acceptable Use Policy</a>
@endsection
