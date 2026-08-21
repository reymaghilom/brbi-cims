<style>
@page{size:8.5in 13in;margin:.28in}
.business-official-sheet{width:100%;border:.8pt solid #111;padding:.1in .15in .08in;font-family:DejaVu Sans,Arial,sans-serif;font-size:5.8pt;line-height:1.1}
.business-official-sheet table{margin:0;border-collapse:collapse;table-layout:fixed;width:100%}
.business-official-sheet th,.business-official-sheet td{border:.55pt solid #111;padding:.025in .035in;vertical-align:middle;overflow-wrap:anywhere}
.business-official-sheet th{background:#fff;font-size:5.3pt;font-weight:700;text-align:left}
.business-official-header{display:table;width:100%;min-height:.48in;padding:.01in .03in .035in}
.business-official-header>div,.business-official-header>img{display:table-cell;vertical-align:middle}
.business-official-header>div{width:78%}
.business-official-header img{width:1.35in;max-height:.4in;object-fit:contain}
.business-title-line{display:table;width:100%}
.business-title-line h1,.business-title-line strong{display:table-cell;margin:0;vertical-align:bottom}
.business-title-line h1{font-size:9pt;line-height:1;width:60%}
.business-title-line strong{color:#1e3a8a;font-size:5.5pt;text-align:center;width:40%}
.business-scope{margin:.035in 0 0;font-size:5.4pt;font-style:italic}
.business-confidential{margin:.05in 0 0;font-size:4.4pt;font-style:italic}
.business-meta th{width:16%;border:0;padding:.02in .03in}
.business-meta td{width:34%;height:.16in}
.business-meta .business-options{border:0;font-size:5pt}
.business-form-table{margin-top:.03in!important}
.business-section-bar th{background:#d9d9d9;padding:.03in;font-size:6.8pt;text-align:center}
.business-choice-list{display:flex;flex-wrap:wrap;align-items:center;gap:.02in .09in}
.business-choice-list span{white-space:nowrap}
.business-profile th{width:14%}
.business-profile td{height:.15in}
.business-options{font-size:4.9pt;white-space:normal}
.business-grid-table th{background:#f2f2f2;text-align:center;font-size:4.7pt}
.business-grid-table td{height:.16in;font-size:5.15pt}
.business-remarks{margin:.03in 0 0;font-size:5.15pt}
.business-remarks>span{display:block;margin-bottom:.02in;font-weight:700}
.business-remarks-box{min-height:.3in;border:.55pt solid #111;background:#fff;padding:.04in .05in;font-size:5.15pt;line-height:1.25;white-space:pre-wrap;overflow-wrap:anywhere}
.business-generic-details td:nth-child(odd){width:22%;font-weight:700;background:#f7f7f7}
.business-generic-details td:nth-child(even){width:28%}
.business-generic-table th{background:#f2f2f2;font-size:4.9pt}
.business-generic-table td{font-size:5.15pt;height:.16in}
.business-align-left{text-align:left}
.business-align-right{text-align:right}
.business-other-income-grid{margin-top:0!important;font-size:6.8pt}
.business-other-income-grid td{height:auto;vertical-align:top;white-space:normal;border-top:0}
.business-other-income-group-title{margin:0 0 .03in;font-weight:700;font-size:7.2pt}
.business-other-income-group-title:not(:first-child){margin-top:.06in}
.business-other-income-item{display:block;position:relative;margin:0 0 .035in;padding-left:.15in;font-size:6.4pt;font-weight:600}
.business-other-income-check{position:absolute;left:0;top:0;width:.15in;font-size:8.5pt;font-weight:400}
@if($pdfMode ?? false)
/* Dompdf's fixed-layout, border-collapse rendering of this report's 25-column grid drifts
   the right edge a few points past the page — confirmed by inspecting the rendered PDF's
   own drawn coordinates (not a box-sizing or margin issue; box-sizing:border-box is already
   global, and the browser Print/Web view — a real rendering engine, not Dompdf — doesn't
   have this bug, so the fix is scoped to PDF generation only). 95.5% is the verified minimum
   reduction that brings the right border back inside the page with a margin matching the
   left, without shrinking text or visibly narrowing the report. */
.business-official-sheet{width:95.5%}
@endif
</style>
