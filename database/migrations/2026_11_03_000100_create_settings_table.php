<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per setting the office can change without a deployment.
 *
 * WHY A TABLE AND NOT A CONFIG FILE. config/visitdasol.php already
 * holds the municipality, the map centre, the booking rules and the
 * payment policy, and it will go on holding them: it is the DEFAULT.
 * A row here is an override, written by a person through a form, and
 * SettingsServiceProvider pushes it back over the config value at boot.
 *
 * That is the whole trick, and it is why this module changes almost
 * nothing elsewhere. Existing code that already reads
 * config('visitdasol.map.zoom') keeps reading exactly that and starts
 * honouring the office's choice without a line being edited.
 *
 * Delete the row and the config default comes back. Nothing is ever
 * destroyed by changing a setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();

            /*
             * Dotted and unique: "general.name", "map.zoom". The prefix
             * before the first dot is the group the form belongs to, and
             * the whole key is what the registry maps onto a config path.
             */
            $table->string('key', 150)->unique();

            /*
             * Everything is stored as text and cast on the way out by
             * the registry, which is the only place that knows what a
             * key means. A JSON column would tempt callers to write
             * shapes the form never validated.
             */
            $table->text('value')->nullable();

            /*
             * Whether the value is encrypted at rest. Only the SMTP
             * password uses this today. It is a flag rather than a
             * guess, so a value written before the flag existed is
             * never fed to the decrypter by mistake.
             */
            $table->boolean('is_encrypted')->default(false);

            /*
             * Who changed it last. Kept for the settings screen itself;
             * the audit trail proper is the existing activity_logs.
             * nullOnDelete because losing the account must not lose the
             * municipality's address with it.
             */
            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The settings screen loads one group at a time.
            $table->index('key', 'settings_key_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
