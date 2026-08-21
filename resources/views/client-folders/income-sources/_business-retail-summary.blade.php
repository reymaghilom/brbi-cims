<section class="business-report-section" aria-label="Branch inspection totals">
    <div class="business-property-summary">
        <label><span class="ui-label">TOTAL BRANCHES DECLARED:</span><input id="branches_declared" class="ui-control" type="text" name="branches_declared" value="{{ old('branches_declared', $report?->branches_declared) }}"><x-form.validation-message for="branches_declared" /></label>
        <label><span class="ui-label">TOTAL BRANCHES INSPECTED:</span><input id="branches_inspected" class="ui-control" type="text" name="branches_inspected" value="{{ old('branches_inspected', $report?->branches_inspected) }}"><x-form.validation-message for="branches_inspected" /></label>
        <label><span class="ui-label"># BRANCHES NOT INSPECTED:</span><input id="branches_not_inspected" class="ui-control" type="text" name="branches_not_inspected" value="{{ old('branches_not_inspected', $report?->branches_not_inspected) }}"><x-form.validation-message for="branches_not_inspected" /></label>
        <label class="business-property-summary-reason"><span class="ui-label">REASON NOT INSPECTED:</span><input id="branches_reason_not_inspected" class="ui-control" type="text" name="branches_reason_not_inspected" value="{{ old('branches_reason_not_inspected', $report?->branches_reason_not_inspected) }}"><x-form.validation-message for="branches_reason_not_inspected" /></label>
    </div>
</section>
