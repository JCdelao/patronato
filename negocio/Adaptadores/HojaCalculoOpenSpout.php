<?php

namespace Patronato\Negocio\Adaptadores;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Patronato\Negocio\Contratos\GeneradorHojaCalculo;
use RuntimeException;

// Patrón Adapter (estructural): adapta la librería OpenSpout a la interfaz GeneradorHojaCalculo del sistema
/**
 * Escribe los libros de Excel con OpenSpout, fila por fila y con poca memoria
 */
class HojaCalculoOpenSpout implements GeneradorHojaCalculo
{
    public function generar(array $hojas): string
    {
        $archivo = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($archivo === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del Excel.');
        }

        try {
            $escritor = new Writer;
            $escritor->openToFile($archivo);
            $negrita = (new Style)->withFontBold(true);
            $primera = true;

            foreach ($hojas as $nombre => $hoja) {
                $actual = $primera ? $escritor->getCurrentSheet() : $escritor->addNewSheetAndMakeItCurrent();
                $actual->setName($nombre);
                $primera = false;

                $escritor->addRow(Row::fromValuesWithStyle($hoja['encabezados'], $negrita));

                foreach ($hoja['filas'] as $fila) {
                    $escritor->addRow(Row::fromValues($fila));
                }
            }

            $escritor->close();

            return (string) file_get_contents($archivo);
        } finally {
            if (is_file($archivo)) {
                unlink($archivo);
            }
        }
    }
}
