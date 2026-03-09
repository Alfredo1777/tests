<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\GPS\Models\MqttDeadLetter;

class MqttDlqList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:mqtt-dlq-list';
    protected $description = 'Muestra los mensajes almacenados en la Dead Letter Queue';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $deads = MqttDeadLetter::orderBy('failed_at', 'desc')->take(20)->get();
        if ($deads->isEmpty()){
            $this->info("La Dead Letter Queue esta vacía. ¡Todo perfecto!");
            return 0;
        }
        $this->warn("Mostrando los ultimos {$deads->count()} mensajes en la DLQ:");

        $headers = ['ID', 'Topic', 'Tipo Error', 'Intentos', 'Fecha de Fallo'];
        $rows = $deads->map(fn($d) => [
            $d->id,
            $d->topic,
            $d->error_type,
            $d->attempts,
            $d->failed_at->toDateTimeString()
        ])->toArray();

        $this->table($headers, $rows);
        $this->info("Usa 'php artisan mqtt:dlq:retry {id}' para reprocesar un mensaje");
        return 0;
    }
}
