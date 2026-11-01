<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    /**
     * Carga los roles del sistema
     */
    public function run(): void
    {
        DB::table('roles')->upsert([
            ['codigo' => 'admin_plataforma', 'nombre' => 'Administrador de la plataforma'],
            ['codigo' => 'admin_tecnico', 'nombre' => 'Administrador técnico'],
            ['codigo' => 'presidente', 'nombre' => 'Presidente'],
            ['codigo' => 'secretario', 'nombre' => 'Secretario'],
            ['codigo' => 'tesorero', 'nombre' => 'Tesorero'],
            ['codigo' => 'fiscal', 'nombre' => 'Fiscal'],
            ['codigo' => 'vocal', 'nombre' => 'Vocal'],
            ['codigo' => 'cobrador', 'nombre' => 'Cobrador'],
            ['codigo' => 'guardia', 'nombre' => 'Guardia'],
            ['codigo' => 'residente', 'nombre' => 'Residente'],
        ], ['codigo'], ['nombre']);
    }
}
