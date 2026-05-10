<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\PanelNotification;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\CompanyWelcomeMailSender;
use App\Services\PanelNotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCompanyWelcomeMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $companyId,
        public ?int $actorUserId = null,
    ) {}

    public function handle(CompanyWelcomeMailSender $sender): void
    {
        $company = Company::query()->find($this->companyId);
        if ($company === null) {
            return;
        }

        if ($company->correo === null || trim((string) $company->correo) === '') {
            return;
        }

        $actor = $this->actorUserId !== null ? User::query()->find($this->actorUserId) : null;
        $companyLabel = $company->nombre.' (ID '.$company->id.')';
        $to = strtolower(trim((string) $company->correo));

        $result = $sender->sendForCompany($company);

        if ($result['sent']) {
            ActivityLogger::log($actor, 'correo_bienvenida_enviado', 'Envió correo de bienvenida a '.$to.' — empresa '.$companyLabel.'.');
            $this->notifyActorOrAdmins(
                PanelNotification::TYPE_MAIL_COMPANY_WELCOME_OK,
                'Correo de bienvenida enviado a '.$to.' ('.$company->nombre.').',
                ['company_id' => $company->id, 'link' => '/admin/empresas'],
            );

            return;
        }

        if ($result['skipped_reason'] === 'no_correo') {
            return;
        }

        $detail = (string) ($result['detail'] ?? '');
        ActivityLogger::log(
            $actor,
            'correo_bienvenida_fallido',
            'No se pudo enviar correo de bienvenida a '.$to.' — empresa '.$companyLabel.'.'.($detail !== '' ? ' '.$detail : '')
        );

        $this->notifyActorOrAdmins(
            PanelNotification::TYPE_MAIL_COMPANY_WELCOME_FAILED,
            'No se pudo enviar el correo de bienvenida a '.$to.' ('.$company->nombre.'). Revise Correo del sistema y los logs.',
            [
                'company_id' => $company->id,
                'link' => '/admin/configuracion/correo-notificaciones',
                'detail' => $detail !== '' ? $detail : null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function notifyActorOrAdmins(string $type, string $message, array $meta): void
    {
        $dispatcher = app(PanelNotificationDispatcher::class);
        if ($this->actorUserId !== null && $this->actorUserId > 0) {
            $dispatcher->notifyUserIds([(int) $this->actorUserId], $type, $message, $meta);
        } else {
            $dispatcher->notifyAdmins($type, $message, $meta);
        }
    }
}
