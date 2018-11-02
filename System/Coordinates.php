<?php
/**
 * Elite Dangerous Star Map
 * @link https://www.edsm.net/
 */

namespace   EDDN\System;

class Coordinates
{
    static private $_userId = 3;

    static public function handle($message, $softwareName, $softwareVersion, $updateCoordinates = false, $preventRenamedSystems = false)
    {
        // Try to find the current system by id64
        if(array_key_exists('SystemAddress', $message))
        {
            $systemsModel   = new \Models_Systems;
            $system         = $systemsModel->getById64($message['SystemAddress']);

            if(!is_null($system))
            {
                $currentSystem = \Component\System::getInstance($system['id']);

                if(is_null($currentSystem->getX()) && $currentSystem->isHidden() === false && array_key_exists('StarPos', $message))
                {
                    $systemCoordinates  = array(
                        'x'  => round($message['StarPos'][0] * 32),
                        'y'  => round($message['StarPos'][1] * 32),
                        'z'  => round($message['StarPos'][2] * 32),
                    );

                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Coordinates:</span>        ' . $currentSystem->getName() . ' (#' . $currentSystem->getId() . ') coordinates inserted.');

                    $systemsModel->updateById(
                        $currentSystem->getId(),
                        array(
                            'x'                 => $systemCoordinates['x'],
                            'y'                 => $systemCoordinates['y'],
                            'z'                 => $systemCoordinates['z'],
                            'lastTrilateration' => new \Zend_Db_Expr('NULL'),
                        )
                    );

                    $insert = array(
                        'refSystem'         => $currentSystem->getId(),
                        'refUser'           => self::$_userId,
                        'x'                 => $systemCoordinates['x'],
                        'y'                 => $systemCoordinates['y'],
                        'z'                 => $systemCoordinates['z'],
                        'dateSubmission'    => new \Zend_Db_Expr('NOW()'),
                    );

                    $systemsCoordinatesTempModel    = new \Models_Systems_CoordinatesTemp;
                    $insert['fromSoftware']         = $softwareName;
                    $insert['fromSoftwareVersion']  = $softwareVersion;

                    $systemsCoordinatesTempModel->insert($insert);

                    return $currentSystem->getId();
                }

                return $system['id'];
            }
        }

        // Do name exists?
        if(array_key_exists('StarSystem', $message) && array_key_exists('StarPos', $message))
        {
            $systemName         = $message['StarSystem'];
            $systemCoordinates  = array(
                'x'  => round($message['StarPos'][0] * 32),
                'y'  => round($message['StarPos'][1] * 32),
                'z'  => round($message['StarPos'][2] * 32),
            );
        }
        else
        {
            return null;
        }

        $systemsModel   = new \Models_Systems;
        $currentSystem  = $systemsModel->getByName($systemName);

        // Insert new system if not found in database
        if(is_null($currentSystem))
        {
            try
            {
            	$insert = array('name' => $systemName);
                $insert = array_merge($insert, $systemCoordinates);

                if(array_key_exists('SystemAddress', $message))
                {
                    $insert['id64'] = $message['SystemAddress'];
                }

                $systemsModel->insert($insert);

                $currentSystem      = $systemsModel->getByName($systemName);

                if(!is_null($currentSystem))
                {
                    $insert = array(
                        'refSystem'         => $currentSystem['id'],
                        'refUser'           => self::$_userId,
                        'x'                 => $systemCoordinates['x'],
                        'y'                 => $systemCoordinates['y'],
                        'z'                 => $systemCoordinates['z'],
                        'dateSubmission'    => new \Zend_Db_Expr('NOW()'),
                    );

                    $systemsCoordinatesTempModel    = new \Models_Systems_CoordinatesTemp;
                    $insert['fromSoftware']         = $softwareName;
                    $insert['fromSoftwareVersion']  = $softwareVersion;

                    $systemsCoordinatesTempModel->insert($insert);
                }

                return $currentSystem['id'];
            }
            catch(\Zend_Db_Exception $e)
            {
            	if(strpos($e->getMessage(), '1062 Duplicate') !== false) // Can happen when the same system is submitted twice during the process
                {
                    $currentSystem = $systemsModel->getByName($systemName);

                    if(!is_null($currentSystem))
                    {
                        $currentSystem = \Component\System::getInstance($currentSystem['id']);

                        if($systemCoordinates['x'] == $currentSystem->getX() && $systemCoordinates['y'] == $currentSystem->getY() && $systemCoordinates['z'] == $currentSystem->getZ())
                        {
                            return $currentSystem->getId();
                        }
                    }
            	}
            }

            \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Coordinates:</span>        ' . $systemName . ' (#' . $currentSystem['id'] . ') created!');
        }

        if(!is_null($currentSystem))
        {
            if(!$currentSystem instanceof \EDSM_System)
            {
                $currentSystem = \Component\System::getInstance($currentSystem['id']);
            }

            // Find if system was merged into another system
            if($currentSystem->isHidden() === true)
            {
                $mergedTo = $currentSystem->getMergedTo();

                if(!is_null($mergedTo) && $preventRenamedSystems === false)
                {
                    // Switch systems when they have been renamed
                    $currentSystem = \Component\System::getInstance($mergedTo);
                }
                else
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Coordinates:</span>        ' . $currentSystem->getName() . ' (#' . $currentSystem->getId() . ') is hidden!');
                    return null;
                }
            }

            // Coordinates are good, return current system
            if($systemCoordinates['x'] == $currentSystem->getX() && $systemCoordinates['y'] == $currentSystem->getY() && $systemCoordinates['z'] == $currentSystem->getZ())
            {
                if(array_key_exists('SystemAddress', $message) && is_null($currentSystem->getId64()))
                {
                    $systemsModel->updateById($currentSystem->getId(), array('id64' => $message['SystemAddress']));
                }

                return $currentSystem->getId();
            }
            // Current system does not have coordinates, save them
            elseif(is_null($currentSystem->getX()) && $updateCoordinates === true)
            {
                \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Coordinates:</span>        ' . $currentSystem->getName() . ' (#' . $currentSystem->getId() . ') coordinates inserted.');

                $systemsModel->updateById(
                    $currentSystem->getId(),
                    array(
                        'x'                 => $systemCoordinates['x'],
                        'y'                 => $systemCoordinates['y'],
                        'z'                 => $systemCoordinates['z'],
                        'lastTrilateration' => new \Zend_Db_Expr('NULL'),
                    )
                );

                $insert = array(
                    'refSystem'         => $currentSystem->getId(),
                    'refUser'           => self::$_userId,
                    'x'                 => $systemCoordinates['x'],
                    'y'                 => $systemCoordinates['y'],
                    'z'                 => $systemCoordinates['z'],
                    'dateSubmission'    => new \Zend_Db_Expr('NOW()'),
                );

                $systemsCoordinatesTempModel    = new \Models_Systems_CoordinatesTemp;
                $insert['fromSoftware']         = $softwareName;
                $insert['fromSoftwareVersion']  = $softwareVersion;

                $systemsCoordinatesTempModel->insert($insert);

                return $currentSystem->getId();
            }
            // Find if a duplicate match the coordinates
            else
            {
                $duplicates = $currentSystem->getDuplicates();

                if(!is_null($duplicates) && is_array($duplicates) && count($duplicates) > 0)
                {
                    foreach($duplicates AS $duplicate)
                    {
                        $currentSystemTest  = \Component\System::getInstance($duplicate);

                        // Try to follow hidden system
                        $mergedTo = $currentSystemTest->getMergedTo();
                        if($currentSystemTest->isHidden() === true && !is_null($mergedTo))
                        {
                            $currentSystemTest = \Component\System::getInstance($mergedTo);
                        }

                        // Coordinates are good for the current duplicate switch system
                        if($systemCoordinates['x'] == $currentSystemTest->getX() && $systemCoordinates['y'] == $currentSystemTest->getY() && $systemCoordinates['z'] == $currentSystemTest->getZ())
                        {
                            return $currentSystemTest->getId();
                        }
                    }
                }
            }

            // No found system match the coordinates, check if it is near
            $distance = \EDSM_System_Distances::calculate($currentSystem, $systemCoordinates);

            if($distance <= 2)
            {
                return $currentSystem->getId();
            }

            // We cannot find any system near the provided coordinates or in the duplicates, simply store temp coordinates
            $insert = array(
                'refSystem'         => $currentSystem->getId(),
                'refUser'           => self::$_userId,
                'x'                 => $systemCoordinates['x'],
                'y'                 => $systemCoordinates['y'],
                'z'                 => $systemCoordinates['z'],
                'dateSubmission'    => new \Zend_Db_Expr('NOW()'),
            );

            $systemsCoordinatesTempModel    = new \Models_Systems_CoordinatesTemp;
            $insert['fromSoftware']         = $softwareName;
            $insert['fromSoftwareVersion']  = $softwareVersion;

            $systemsCoordinatesTempModel->insert($insert);

            // Return the first matched system
            return $currentSystem->getId();
        }

        return null;
    }
}