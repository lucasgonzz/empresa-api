<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPersonalityTemplateToWhatsappBotConfigs extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('whatsapp_bot_configs', 'agent_personality_template')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->string('agent_personality_template', 30)->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('whatsapp_bot_configs', 'agent_personality_template')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->dropColumn('agent_personality_template');
            });
        }
    }
}
