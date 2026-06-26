<?php

use App\Models\Template;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->string('purpose')->nullable()->after('is_active');
            $table->index('purpose');
        });

        // Seed missing_phone template if it doesn't exist
        if (! Template::where('purpose', 'missing_phone')->exists()) {
            Template::firstOrCreate(
                ['purpose' => 'missing_phone'],
                [
                    'name' => 'Missing Phone',
                    'subject' => 'Solicitud de datos de contacto',
                    'body' => "Estimado/a,\n\nNos ponemos en contacto con usted para solicitar sus datos de teléfono de contacto, necesarios para poder gestionar adecuadamente su solicitud.\n\nPor favor, responda a este correo indicando su número de teléfono.\n\nAtentamente.",
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropIndex(['purpose']);
            $table->dropColumn('purpose');
        });
    }
};
