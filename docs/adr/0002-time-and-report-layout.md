# ADR 0002: Time and official report layout

Status: Accepted

Database timestamps and the Laravel application timezone remain UTC. User-facing dates and times are converted to `Asia/Manila` through CIMS presentation configuration.

Official BRBI CI/BI, Business/Income Source and Residence & Business Photo reports default to 8.5 x 13 inch paper. Template-specific margins and page-break rules belong to dedicated output templates. A future official template may explicitly declare a different paper size.
