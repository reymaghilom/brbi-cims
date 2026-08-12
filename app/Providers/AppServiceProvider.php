<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\ClientInformation;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Models\MediaReference;
use App\Models\ResidenceBusinessReport;
use App\Models\SystemSetting;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\CiActivityPolicy;
use App\Policies\CibiReportPolicy;
use App\Policies\ClientFolderPolicy;
use App\Policies\ClientInformationPolicy;
use App\Policies\GeneratedReportPolicy;
use App\Policies\IncomeSourcePolicy;
use App\Policies\MediaReferencePolicy;
use App\Policies\ResidenceBusinessReportPolicy;
use App\Policies\SystemSettingPolicy;
use App\Policies\TelegramMessagePolicy;
use App\Policies\UserPolicy;
use App\Services\Reports\Contracts\DocxGenerator;
use App\Services\Reports\Contracts\PdfGenerator;
use App\Services\Reports\DompdfOfficialReportGenerator;
use App\Services\Reports\PhpWordOfficialReportGenerator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PdfGenerator::class, DompdfOfficialReportGenerator::class);
        $this->app->bind(DocxGenerator::class, PhpWordOfficialReportGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(ClientFolder::class, ClientFolderPolicy::class);
        Gate::policy(ClientInformation::class, ClientInformationPolicy::class);
        Gate::policy(CibiReport::class, CibiReportPolicy::class);
        Gate::policy(IncomeSource::class, IncomeSourcePolicy::class);
        Gate::policy(ResidenceBusinessReport::class, ResidenceBusinessReportPolicy::class);
        Gate::policy(CiActivity::class, CiActivityPolicy::class);
        Gate::policy(MediaReference::class, MediaReferencePolicy::class);
        Gate::policy(GeneratedReport::class, GeneratedReportPolicy::class);
        Gate::policy(TelegramMessage::class, TelegramMessagePolicy::class);
        Gate::policy(SystemSetting::class, SystemSettingPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
    }
}
