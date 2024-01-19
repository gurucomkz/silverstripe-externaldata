<?php
namespace Gurucomkz\ExternalData\Extensions;

use Gurucomkz\ExternalData\Model\ExternalDataObject;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extension;

class ExternalBuildExtension extends Extension
{
    public function onAfterBuild($quiet, $populate, $testMode)
    {
        $dataClasses = ClassInfo::subclassesFor(ExternalDataObject::class);
        array_shift($dataClasses);

        if ($populate) {
            if (!$quiet) {
                if (Director::is_cli()) {
                    echo "\nCREATING EXTERNAL DATABASE RECORDS\n\n";
                } else {
                    echo "\n<p><b>Creating external database records</b></p><ul>\n\n";
                }
            }

            // Require all default records
            foreach ($dataClasses as $dataClass) {
                // Check if class exists before trying to instantiate - this sidesteps any manifest weirdness
                // Test_ indicates that it's the data class is part of testing system
                if (strpos($dataClass ?? '', 'Test_') === false && class_exists($dataClass ?? '')) {
                    if (!$quiet) {
                        if (Director::is_cli()) {
                            echo " * $dataClass\n";
                        } else {
                            echo "<li>$dataClass</li>\n";
                        }
                    }

                    ExternalDataObject::singleton($dataClass)->requireDefaultRecords();
                }
            }

            if (!$quiet && !Director::is_cli()) {
                echo "</ul>";
            }
        }
    }
}
