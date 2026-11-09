<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_certificacion_catalogo_lock', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
        });
        DB::table('mcp_certificacion_catalogo_lock')->insert(['id' => 1]);
        Schema::create('mcp_certificacion_operaciones', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->uuid('clave');
            $t->string('huella', 64);
            $t->text('respuesta')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'clave']);
        });
        Schema::create('mcp_certificacion_subidas', function (Blueprint $t) {
            $t->id();
            $t->integer('solicitud_id');
            $t->integer('instrumento_id');
            $t->unsignedBigInteger('user_id');
            $t->string('serie')->nullable();
            $t->string('token_hash', 64)->unique();
            $t->string('version', 64);
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_certificacion_subidas');
        Schema::dropIfExists('mcp_certificacion_operaciones');
        Schema::dropIfExists('mcp_certificacion_catalogo_lock');
    }
};
