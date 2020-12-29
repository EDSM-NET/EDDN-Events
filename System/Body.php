<?php
/**
 * Elite Dangerous Star Map
 * @link https://www.edsm.net/
 */

namespace   EDDN\System;

use         Alias\Body\Star\Type                        as StarType;
use         Alias\Body\Planet\Type                      as PlanetType;
use         Alias\Body\Ring\Type                        as RingType;

use         Alias\Body\Planet\Atmosphere;
use         Alias\Body\Planet\Volcanism;
use         Alias\Body\Planet\ReserveLevel;
use         Alias\Body\Planet\TerraformState;
use         Alias\Body\Planet\Material                  as Material;
use         Alias\Body\Planet\AtmosphereComposition     as AtmosphereComposition;
use         Alias\Body\Planet\SolidComposition          as SolidComposition;

class Body
{
    static public function handle($systemId, $message, $useLogger = true)
    {
        $wasInserted    = false;
        $currentSystem  = \Component\System::getInstance($systemId);

        if($message['BodyName'] == 'Athaip QP-E d12-9178 6')
        {
            $message['BodyName'] = 'Jasmine\'s Playground';
        }

        if($currentSystem->isValid() && $currentSystem->isHidden() === false)
        {
            $currentBody                = null;

            $systemsBodiesModel         = new \Models_Systems_Bodies;
            $systemsBodiesParentsModel  = new \Models_Systems_Bodies_Parents;
            $systemsBodiesSurfaceModel  = new \Models_Systems_Bodies_Surface;
            $systemsBodiesOrbitalModel  = new \Models_Systems_Bodies_Orbital;

            // Is it an aliased body name or can we remove the system name from it?
            $bodyName   = $message['BodyName'];
            $isAliased  = \Alias\Body\Name::isAliased($systemId, $bodyName);

            if($isAliased === false)
            {
                $systemName = $currentSystem->getName();

                if(substr(strtolower($bodyName), 0, strlen($systemName)) == strtolower($systemName))
                {
                    $bodyName = trim(str_ireplace($systemName, '', $bodyName));
                }
            }

            // Try to find body by name/refSystem
            if(is_null($currentBody))
            {
                // Use cache to fetch all bodies in the current system
                $systemBodies = $systemsBodiesModel->getByRefSystem($systemId);

                if(!is_null($systemBodies) && count($systemBodies) > 0)
                {
                    foreach($systemBodies AS $currentSystemBody)
                    {
                        // Complete name format or just body part
                        if(trim(strtolower($currentSystemBody['name'])) == strtolower($bodyName) || trim(strtolower($currentSystemBody['name'])) == strtolower($message['BodyName']))
                        {
                            $currentBody = $currentSystemBody['id'];

                            if(array_key_exists('dateUpdated', $currentSystemBody))
                            {
                                // Discard older message...
                                if(strtotime($message['timestamp']) < strtotime($currentSystemBody['dateUpdated']))
                                {
                                    return $currentSystemBody['id'];
                                }

                                // Fill current data
                                $currentBodyData    = $systemsBodiesModel->getById($currentBody);
                            }
                            else
                            {
                                $currentBodyData    = $systemsBodiesModel->getById($currentBody);

                                // Discard older message...
                                if(strtotime($message['timestamp']) < strtotime($currentBodyData['dateUpdated']))
                                {
                                    return $currentSystemBody['id'];
                                }
                            }

                            break;
                        }
                    }
                }

                if(is_null($currentBody))
                {
                    // Check name by converting the body name to the real procgen name
                    $testName = \Alias\Body\Name::get($currentSystem->getId(), $message['BodyName']);

                    if(
                           (array_key_exists('StarSystem', $message) && stripos($testName, $message['StarSystem']) === false)
                        || (array_key_exists('_systemName', $message) && stripos($testName, $message['_systemName']) === false)
                    )
                    {
                        // Assume coming from Scan journal event, return false to put in temp table
                        if($useLogger === false)
                        {
                            return false;
                        }

                        return null;
                    }

                    if(array_key_exists('StarType', $message) || array_key_exists('PlanetClass', $message))
                    {
                        $insert = array(
                            'refSystem'     => $systemId,
                            'name'          => $bodyName,
                        );

                        if(array_key_exists('StarType', $message))
                        {
                            $insert['group']    = 1;
                            $starType           = StarType::getFromFd($message['StarType']);

                            if(!is_null($starType))
                            {
                                $insert['type'] = $starType;
                            }
                            else
                            {
                                \EDSM_Api_Logger_Alias::log('Alias\Body\Star\Type #' . $currentBody . ':' . $message['StarType']);
                                return false;
                            }
                        }
                        elseif(array_key_exists('PlanetClass', $message))
                        {
                            $insert['group']    = 2;
                            $planetType         = PlanetType::getFromFd($message['PlanetClass']);

                            if(!is_null($planetType))
                            {
                                $insert['type'] = $planetType;
                            }
                            else
                            {
                                \EDSM_Api_Logger_Alias::log('Alias\Body\Planet\Type #' . $currentBody . ':' . $message['PlanetClass']);
                                return false;
                            }
                        }

                        if(array_key_exists('BodyID', $message))
                        {
                            $insert['id64'] = $message['BodyID'];
                        }

                        try
                        {
                            $currentBody        = $systemsBodiesModel->insert($insert);
                            $currentBodyData    = array(); // Empty array to avoid getting the line from the database

                            if($useLogger === true)
                            {
                                \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Body:</span>               ' . $message['BodyName'] . ' (#' . $currentBody . ') inserted.');
                                $wasInserted = true;
                            }
                        }
                        catch(\Zend_Db_Exception $e)
                        {
                            // Based on unique index, this body entry was already saved.
                            if(strpos($e->getMessage(), '1062 Duplicate') !== false)
                            {
                                // Use cache to fetch all bodies in the current system
                                $systemBodies = $systemsBodiesModel->getByRefSystem($systemId);

                                if(!is_null($systemBodies) && count($systemBodies) > 0)
                                {
                                    foreach($systemBodies AS $currentSystemBody)
                                    {
                                        // Complete name format or just body part
                                        if(trim(strtolower($currentSystemBody['name'])) == strtolower($bodyName) || trim(strtolower($currentSystemBody['name'])) == strtolower($message['BodyName']))
                                        {
                                            $currentBody = $currentSystemBody['id'];

                                            if(array_key_exists('dateUpdated', $currentSystemBody))
                                            {
                                                // Discard older message...
                                                if(strtotime($message['timestamp']) < strtotime($currentSystemBody['dateUpdated']))
                                                {
                                                    return $currentSystemBody['id'];
                                                }

                                                // Fill current data
                                                $currentBodyData    = $systemsBodiesModel->getById($currentBody);
                                            }
                                            else
                                            {
                                                $currentBodyData    = $systemsBodiesModel->getById($currentBody);

                                                // Discard older message...
                                                if(strtotime($message['timestamp']) < strtotime($currentBodyData['dateUpdated']))
                                                {
                                                    return $currentSystemBody['id'];
                                                }
                                            }

                                            break;
                                        }
                                    }
                                }
                            }
                            else
                            {
                                if(defined('APPLICATION_SENTRY') && APPLICATION_SENTRY === true)
                                {
                                    \Sentry\captureException($e);
                                }
                            }
                        }
                    }
                    else
                    {
                        // Belt cluster are just useless, we rely on belts sent with the parent body!
                        return null;
                    }
                }
            }

            if(!is_null($currentBody))
            {
                $updateElasticStatus        = false;
                $currentBodyNewData         = array();
                $currentBodyNewParentsData  = array();
                $currentBodyNewSurfaceData  = array();
                $currentBodyNewOrbitalData  = array();

                if(!array_key_exists('name', $currentBodyData) || $bodyName != $currentBodyData['name'])
                {
                    $currentBodyNewData['name'] = $bodyName;
                }

                if(array_key_exists('BodyID', $message))
                {
                    if(!array_key_exists('id64', $currentBodyData) || $currentBodyData['id64'] != $message['BodyID'])
                    {
                        $currentBodyNewData['id64'] = $message['BodyID'];
                    }
                }

                if(array_key_exists('Parents', $message))
                {
                    try
                    {
                        $message['Parents'] = \Zend_Json::encode($message['Parents']);
                    }
                    catch(\Zend_Json_Exception $e)
                    {
                        $message['Parents'] = null;

                        if(defined('APPLICATION_SENTRY') && APPLICATION_SENTRY === true)
                        {
                            \Sentry\captureException($e);
                        }
                    }

                    if(!is_null($message['Parents']) && (!array_key_exists('parents', $currentBodyData) || $message['Parents'] != $currentBodyData['parents']))
                    {
                        $currentBodyNewParentsData['parents'] = $message['Parents'];
                    }
                }

                if(array_key_exists('StarType', $message))
                {
                    if(!array_key_exists('group', $currentBodyData) || $currentBodyData['group'] != 1)
                    {
                        $currentBodyNewData['group'] = 1;
                    }

                    $starType = StarType::getFromFd($message['StarType']);

                    if(is_null($starType))
                    {
                        \EDSM_Api_Logger_Alias::log('Alias\Body\Star\Type #' . $currentBody . ':' . $message['StarType']);
                    }

                    if(!array_key_exists('type', $currentBodyData) || $starType != $currentBodyData['type'])
                    {
                        $currentBodyNewData['type'] = $starType;
                    }

                    if(array_key_exists('Luminosity', $message) && (!array_key_exists('luminosity', $currentBodyData) || $message['Luminosity'] != $currentBodyData['luminosity']))
                    {
                        $currentBodyNewData['luminosity'] = $message['Luminosity'];
                    }

                    if(array_key_exists('AbsoluteMagnitude', $message) && (!array_key_exists('absoluteMagnitude', $currentBodyData) || $message['AbsoluteMagnitude'] != $currentBodyData['absoluteMagnitude']))
                    {
                        $currentBodyNewData['absoluteMagnitude'] = $message['AbsoluteMagnitude'];
                    }
                }
                elseif(array_key_exists('PlanetClass', $message))
                {
                    if(!array_key_exists('group', $currentBodyData) || $currentBodyData['group'] != 2)
                    {
                        $currentBodyNewData['group'] = 2;
                    }

                    $planetType = PlanetType::getFromFd($message['PlanetClass']);

                    if(is_null($planetType))
                    {
                        \EDSM_Api_Logger_Alias::log('Alias\Body\Planet\Type #' . $currentBody . ':' . $message['PlanetClass']);
                    }

                    if(!array_key_exists('type', $currentBodyData) || $planetType != $currentBodyData['type'])
                    {
                        $currentBodyNewData['type'] = $planetType;
                    }

                    if(array_key_exists('TidalLock', $message))
                    {
                        if($message['TidalLock'] == true && (!array_key_exists('rotationalPeriodTidallyLocked', $currentBodyData) || $currentBodyData['rotationalPeriodTidallyLocked'] != 1))
                        {
                            $currentBodyNewOrbitalData['rotationalPeriodTidallyLocked'] = 1;
                        }
                        elseif(!array_key_exists('rotationalPeriodTidallyLocked', $currentBodyData) || is_null($currentBodyData['rotationalPeriodTidallyLocked']) || $currentBodyData['rotationalPeriodTidallyLocked'] != 0)
                        {
                            $currentBodyNewOrbitalData['rotationalPeriodTidallyLocked'] = 0;
                        }
                    }

                    if(array_key_exists('Materials', $message) && count($message['Materials']) > 0) // Force landable ;)
                    {
                        if(!array_key_exists('isLandable', $currentBodyData) || $currentBodyData['isLandable'] != 1)
                        {
                            $currentBodyNewData['isLandable'] = 1;
                        }
                    }
                    elseif(array_key_exists('Landable', $message))
                    {
                        if($message['Landable'] == true && (!array_key_exists('isLandable', $currentBodyData) || $currentBodyData['isLandable'] != 1))
                        {
                            $currentBodyNewData['isLandable'] = 1;
                        }
                        elseif(!array_key_exists('isLandable', $currentBodyData) || $currentBodyData['isLandable'] != 0)
                        {
                            $currentBodyNewData['isLandable'] = 0;
                        }
                    }
                }

                // General variables
                if(array_key_exists('DistanceFromArrivalLS', $message))
                {
                    // Only keep integer
                    $message['DistanceFromArrivalLS'] = round($message['DistanceFromArrivalLS']);

                    if(!array_key_exists('distanceToArrival', $currentBodyData) || is_null($currentBodyData['distanceToArrival']))
                    {
                        $currentBodyNewData['distanceToArrival'] = $message['DistanceFromArrivalLS'];
                    }
                    elseif($message['DistanceFromArrivalLS'] != $currentBodyData['distanceToArrival'])
                    {
                        if(abs($message['DistanceFromArrivalLS'] - $currentBodyData['distanceToArrival']) >= 5)
                        {
                            $currentBodyNewData['distanceToArrival'] = $message['DistanceFromArrivalLS'];
                        }
                    }
                }

                // Reserve Level
                if(array_key_exists('ReserveLevel', $message))
                {
                    $reserveLevel = ReserveLevel::getFromFd($message['ReserveLevel']);

                    if(!is_null($reserveLevel))
                    {
                        if(!array_key_exists('distanceToArrival', $currentBodyData) || $currentBodyData['reserveLevel'] != $reserveLevel)
                        {
                            $currentBodyNewData['reserveLevel'] = $reserveLevel;
                        }
                    }
                    else
                    {
                        \EDSM_Api_Logger_Alias::log('Alias\Body\Planet\ReserveLevel #' . $currentBody . ':' . $message['ReserveLevel']);
                    }
                }

                // Surface variables
                if(array_key_exists('Radius', $message) && (!array_key_exists('radius', $currentBodyData) || $message['Radius'] != $currentBodyData['radius']))
                {
                    $currentBodyNewSurfaceData['radius'] = $message['Radius'];
                }
                if(array_key_exists('SurfaceTemperature', $message) && (!array_key_exists('surfaceTemperature', $currentBodyData) || $message['SurfaceTemperature'] != $currentBodyData['surfaceTemperature']))
                {
                    $currentBodyNewSurfaceData['surfaceTemperature'] = $message['SurfaceTemperature'];
                }

                if(array_key_exists('StarType', $message))
                {
                    if(array_key_exists('Age_MY', $message) && (!array_key_exists('age', $currentBodyData) || $message['Age_MY'] != $currentBodyData['age']))
                    {
                        $currentBodyNewSurfaceData['age'] = $message['Age_MY'];
                    }

                    if(array_key_exists('StellarMass', $message) && (!array_key_exists('mass', $currentBodyData) || $message['StellarMass'] != $currentBodyData['mass']))
                    {
                        $currentBodyNewSurfaceData['mass'] = $message['StellarMass'];
                    }
                }
                elseif(array_key_exists('PlanetClass', $message))
                {
                    if(array_key_exists('MassEM', $message) && (!array_key_exists('mass', $currentBodyData) || $message['MassEM'] != $currentBodyData['mass']))
                    {
                        $currentBodyNewSurfaceData['mass'] = $message['MassEM'];
                    }

                    if(array_key_exists('SurfacePressure', $message) && (!array_key_exists('surfacePressure', $currentBodyData) || $message['SurfacePressure'] != $currentBodyData['surfacePressure']))
                    {
                        $currentBodyNewSurfaceData['surfacePressure'] = $message['SurfacePressure'];
                    }

                    if(array_key_exists('Atmosphere', $message))
                    {
                        $prefix     = Atmosphere::getPrefixFromFd($message['Atmosphere']);
                        $atmosphere = Atmosphere::getFromFd($message['Atmosphere'], ( (array_key_exists('AtmosphereType', $message)) ? $message['AtmosphereType'] : null ));

                        if((is_null($atmosphere) || $atmosphere == 0) && !empty($message['Atmosphere']) && array_key_exists('AtmosphereType', $message))
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\Body\Planet\Atmosphere #' . $currentBody . ':' . $message['Atmosphere'] . ':' . $message['AtmosphereType']);
                        }

                        if(!array_key_exists('atmospherePrefix', $currentBodyData) || $prefix != $currentBodyData['atmospherePrefix'])
                        {
                            $currentBodyNewSurfaceData['atmospherePrefix'] = $prefix;
                        }
                        if(!array_key_exists('atmosphereType', $currentBodyData) || $atmosphere != $currentBodyData['atmosphereType'])
                        {
                            $currentBodyNewSurfaceData['atmosphereType'] = $atmosphere;
                        }
                    }

                    if(array_key_exists('Volcanism', $message))
                    {
                        $prefix     = Volcanism::getPrefixFromFd($message['Volcanism']);
                        $volcanism  = Volcanism::getFromFd($message['Volcanism']);

                        if((is_null($volcanism) || $volcanism == 0) && !empty($message['Volcanism']))
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\Body\Planet\Volcanism #' . $currentBody . ':' . $message['Volcanism']);
                        }

                        if(!array_key_exists('volcanismPrefix', $currentBodyData) || $prefix != $currentBodyData['volcanismPrefix'])
                        {
                            $currentBodyNewSurfaceData['volcanismPrefix'] = $prefix;
                        }
                        if(!array_key_exists('volcanismType', $currentBodyData) || $volcanism != $currentBodyData['volcanismType'])
                        {
                            $currentBodyNewSurfaceData['volcanismType'] = $volcanism;
                        }
                    }

                    if(array_key_exists('TerraformState', $message))
                    {
                        if(!is_null($message['TerraformState']) && !empty($message['TerraformState']))
                        {
                            $terraformState = TerraformState::getFromFd($message['TerraformState']);
                        }
                        else
                        {
                            $terraformState = 0;
                        }

                        if(!is_null($terraformState))
                        {
                            if(!array_key_exists('terraformingState', $currentBodyData) || $terraformState != $currentBodyData['terraformingState'])
                            {
                                $currentBodyNewSurfaceData['terraformingState'] = $terraformState;
                            }
                        }
                        else
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\Body\Planet\TerraformState #' . $currentBody . ':' . $message['TerraformState']);
                        }
                    }
                }

                // Orbital variables
                if(array_key_exists('RotationPeriod', $message) && (!array_key_exists('rotationalPeriod', $currentBodyData) || $message['RotationPeriod'] != $currentBodyData['rotationalPeriod']))
                {
                    $currentBodyNewOrbitalData['rotationalPeriod'] = $message['RotationPeriod'];
                }
                if(array_key_exists('SemiMajorAxis', $message) && (!array_key_exists('semiMajorAxis', $currentBodyData) || $message['SemiMajorAxis'] != $currentBodyData['semiMajorAxis']))
                {
                    $currentBodyNewOrbitalData['semiMajorAxis'] = $message['SemiMajorAxis'];
                }
                if(array_key_exists('Eccentricity', $message) && (!array_key_exists('orbitalEccentricity', $currentBodyData) || $message['Eccentricity'] != $currentBodyData['orbitalEccentricity']))
                {
                    $currentBodyNewOrbitalData['orbitalEccentricity'] = $message['Eccentricity'];
                }
                if(array_key_exists('OrbitalInclination', $message) && (!array_key_exists('orbitalInclination', $currentBodyData) || $message['OrbitalInclination'] != $currentBodyData['orbitalInclination']))
                {
                    $currentBodyNewOrbitalData['orbitalInclination'] = $message['OrbitalInclination'];
                }
                if(array_key_exists('Periapsis', $message) && (!array_key_exists('argOfPeriapsis', $currentBodyData) || $message['Periapsis'] != $currentBodyData['argOfPeriapsis']))
                {
                    $currentBodyNewOrbitalData['argOfPeriapsis'] = $message['Periapsis'];
                }
                if(array_key_exists('OrbitalPeriod', $message) && (!array_key_exists('orbitalPeriod', $currentBodyData) || $message['OrbitalPeriod'] != $currentBodyData['orbitalPeriod']))
                {
                    $currentBodyNewOrbitalData['orbitalPeriod'] = $message['OrbitalPeriod'];
                }
                if(array_key_exists('AxialTilt', $message) && (!array_key_exists('axisTilt', $currentBodyData) || $message['AxialTilt'] != $currentBodyData['axisTilt']))
                {
                    $currentBodyNewOrbitalData['axisTilt'] = $message['AxialTilt'];
                }

                // Atmosphere composition
                if(array_key_exists('AtmosphereComposition', $message) && count($message['AtmosphereComposition']) > 0)
                {
                    $systemsBodiesAtmosphereCompositionModel    = new \Models_Systems_Bodies_AtmosphereComposition;
                    $oldComposition                             = $systemsBodiesAtmosphereCompositionModel->getByRefBody($currentBody);
                    $composition                                = array();

                    foreach($message['AtmosphereComposition'] AS $component)
                    {
                        $componentType = AtmosphereComposition::getFromFd($component['Name']);

                        if(is_null($componentType))
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\Body\Planet\AtmosphereComposition #' . $currentBody . ':' . $component['Name']);
                        }
                        else
                        {
                            $composition[$componentType] = $component['Percent'];
                        }
                    }

                    foreach($composition AS $type => $qty)
                    {
                        $oldComponent   = null;
                        $qty            = round($qty * 100);

                        foreach($oldComposition AS $key => $values)
                        {
                            if($values['refComposition'] == $type)
                            {
                                unset($oldComposition[$key]);
                                $oldComponent = $values;
                                break;
                            }
                        }

                        // Update QTY if composition was already stored
                        if(!is_null($oldComponent))
                        {
                            if($oldComponent['percent'] != $qty)
                            {
                                $systemsBodiesAtmosphereCompositionModel->updateByRefBodyAndRefComposition($currentBody, $oldComponent['refComposition'], array(
                                    'percent'       => $qty,
                                ));
                                $updateElasticStatus = true; // Force Elastic refresh in the background process
                            }
                        }
                        // Insert new composition
                        else
                        {
                            try
                            {
                                $systemsBodiesAtmosphereCompositionModel->insert(array(
                                    'refBody'           => $currentBody,
                                    'refComposition'    => $type,
                                    'percent'           => $qty,
                                ));
                                $updateElasticStatus = true; // Force Elastic refresh in the background process
                            }
                            catch(\Zend_Db_Exception $e)
                            {
                                // Based on unique index, this entry was already saved.
                                if(strpos($e->getMessage(), '1062 Duplicate') !== false)
                                {

                                }
                                else
                                {
                                    if(defined('APPLICATION_SENTRY') && APPLICATION_SENTRY === true)
                                    {
                                        \Sentry\captureException($e);
                                    }
                                }
                            }
                        }
                    }

                    // Remove remaining composition
                    if(count($oldComposition) > 0)
                    {
                        foreach($oldComposition AS $values)
                        {
                            $systemsBodiesAtmosphereCompositionModel->deleteByRefBodyAndRefComposition($currentBody, $values['refComposition']);
                            $updateElasticStatus = true; // Force Elastic refresh in the background process
                        }
                    }

                    unset($composition, $oldComposition);
                }
                elseif(array_key_exists('ScanType', $message) && in_array($message['ScanType'], array('Detailed', 'NavBeaconDetail')))
                {
                    $systemsBodiesAtmosphereCompositionModel    = new \Models_Systems_Bodies_AtmosphereComposition;
                    $oldComposition                             = $systemsBodiesAtmosphereCompositionModel->getByRefBody($currentBody);

                    if(is_null($oldComposition) || count($oldComposition) == 0)
                    {
                        $systemsBodiesAtmosphereCompositionModel->deleteByRefBody($currentBody);
                        $updateElasticStatus = true; // Force Elastic refresh in the background process
                    }
                }

                // Solid composition
                if(array_key_exists('Composition', $message) && count($message['Composition']) > 0)
                {
                    $systemsBodiesSolidCompositionModel         = new \Models_Systems_Bodies_SolidComposition;
                    $oldComposition                             = $systemsBodiesSolidCompositionModel->getByRefBody($currentBody);
                    $composition                                = array();

                    foreach($message['Composition'] AS $component => $qty)
                    {
                        $componentType = SolidComposition::getFromFd($component);

                        if(is_null($componentType))
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\Body\Planet\SolidComposition #' . $currentBody . ':' . $component);
                        }
                        else
                        {
                            $composition[$componentType] = $qty;
                        }
                    }

                    foreach($composition AS $type => $qty)
                    {
                        $oldComponent   = null;
                        $qty            = round($qty * 10000);

                        foreach($oldComposition AS $key => $values)
                        {
                            if($values['refComposition'] == $type)
                            {
                                unset($oldComposition[$key]);
                                $oldComponent = $values;
                                break;
                            }
                        }

                        // Update QTY if composition was already stored
                        if(!is_null($oldComponent))
                        {
                            if($oldComponent['percent'] != $qty)
                            {
                                $systemsBodiesSolidCompositionModel->updateByRefBodyAndRefComposition($currentBody, $oldComponent['refComposition'], array(
                                    'percent'       => $qty,
                                ));
                                $updateElasticStatus = true; // Force Elastic refresh in the background process
                            }
                        }
                        // Insert new composition
                        else
                        {
                            try
                            {
                                $systemsBodiesSolidCompositionModel->insert(array(
                                    'refBody'           => $currentBody,
                                    'refComposition'    => $type,
                                    'percent'           => $qty,
                                ));
                                $updateElasticStatus = true; // Force Elastic refresh in the background process
                            }
                            catch(\Zend_Db_Exception $e)
                            {
                                // Based on unique index, this entry was already saved.
                                if(strpos($e->getMessage(), '1062 Duplicate') !== false)
                                {

                                }
                                else
                                {
                                    if(defined('APPLICATION_SENTRY') && APPLICATION_SENTRY === true)
                                    {
                                        \Sentry\captureException($e);
                                    }
                                }
                            }
                        }
                    }

                    // Remove remaining composition
                    if(count($oldComposition) > 0)
                    {
                        foreach($oldComposition AS $values)
                        {
                            $systemsBodiesSolidCompositionModel->deleteByRefBodyAndRefComposition($currentBody, $values['refComposition']);
                            $updateElasticStatus = true; // Force Elastic refresh in the background process
                        }
                    }

                    unset($composition, $oldComposition);
                }
                elseif(array_key_exists('ScanType', $message) && in_array($message['ScanType'], array('Detailed', 'NavBeaconDetail')))
                {
                    $systemsBodiesSolidCompositionModel         = new \Models_Systems_Bodies_SolidComposition;
                    $oldComposition                             = $systemsBodiesSolidCompositionModel->getByRefBody($currentBody);

                    if(is_null($oldComposition) || count($oldComposition) == 0)
                    {
                        $systemsBodiesSolidCompositionModel->deleteByRefBody($currentBody);
                        $updateElasticStatus = true; // Force Elastic refresh in the background process
                    }
                }

                // Materials
                if(array_key_exists('Materials', $message) && count($message['Materials']) > 0)
                {
                    // Convert old 2.2 to 2.3 format
                    if(!is_array(array_values($message['Materials'])[0])) // array_values ensure the first key is numeric
                    {
                        $tempMaterials = array();

                        foreach($message['Materials'] AS $key => $value)
                        {
                            $tempMaterials[] = array(
                                'Name'      => $key,
                                'Percent'   => $value,
                            );
                        }

                        $message['Materials'] = $tempMaterials;
                    }

                    $systemsBodiesMaterialsModel    = new \Models_Systems_Bodies_Materials;
                    $oldMaterials                   = $systemsBodiesMaterialsModel->getByRefBody($currentBody);
                    $materials                      = array();

                    foreach($message['Materials'] AS $material)
                    {
                        $materialType = Material::getFromFd($material['Name']);

                        if(is_null($materialType))
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\Body\Planet\Material #' . $currentBody . ':' . $material['Name']);
                        }
                        else
                        {
                            $materials[$materialType] = $material['Percent'];
                        }
                    }

                    foreach($materials AS $type => $qty)
                    {
                        $oldMaterial    = null;
                        $qty            = round($qty * 100);

                        foreach($oldMaterials AS $key => $values)
                        {
                            if($values['refMaterial'] == $type)
                            {
                                unset($oldMaterials[$key]);
                                $oldMaterial = $values;
                                break;
                            }
                        }

                        // Update QTY if materials was already stored
                        if(!is_null($oldMaterial))
                        {
                            if($oldMaterial['percent'] != $qty)
                            {
                                $systemsBodiesMaterialsModel->updateByRefBodyAndRefMaterial($currentBody, $oldMaterial['refMaterial'], array(
                                    'percent'       => $qty,
                                ));
                                $updateElasticStatus = true; // Force Elastic refresh in the background process
                            }
                        }
                        // Insert new material
                        else
                        {
                            try
                            {
                                $systemsBodiesMaterialsModel->insert(array(
                                    'refBody'       => $currentBody,
                                    'refMaterial'   => $type,
                                    'percent'       => $qty,
                                ));
                                $updateElasticStatus = true; // Force Elastic refresh in the background process
                            }
                            catch(\Zend_Db_Exception $e)
                            {
                                // Based on unique index, this entry was already saved.
                                if(strpos($e->getMessage(), '1062 Duplicate') !== false)
                                {

                                }
                                else
                                {
                                    if(defined('APPLICATION_SENTRY') && APPLICATION_SENTRY === true)
                                    {
                                        \Sentry\captureException($e);
                                    }
                                }
                            }
                        }
                    }

                    // Remove remaining material
                    if(count($oldMaterials) > 0)
                    {
                        foreach($oldMaterials AS $values)
                        {
                            $systemsBodiesMaterialsModel->deleteByRefBodyAndRefMaterial($currentBody, $values['refMaterial']);
                            $updateElasticStatus = true; // Force Elastic refresh in the background process
                        }
                    }

                    unset($materials);
                }
                elseif(array_key_exists('ScanType', $message) && in_array($message['ScanType'], array('Detailed', 'NavBeaconDetail')))
                {
                    $systemsBodiesMaterialsModel    = new \Models_Systems_Bodies_Materials;
                    $oldMaterials                   = $systemsBodiesMaterialsModel->getByRefBody($currentBody);

                    if(is_null($oldMaterials) || count($oldMaterials) == 0)
                    {
                        $systemsBodiesMaterialsModel->deleteByRefBody($currentBody);
                        $updateElasticStatus = true; // Force Elastic refresh in the background process
                    }
                }

                // Rings
                if(array_key_exists('Rings', $message) && count($message['Rings']) > 0)
                {
                    $systemsBodiesBeltsModel        = new \Models_Systems_Bodies_Belts;
                    $systemsBodiesRingsModel        = new \Models_Systems_Bodies_Rings;
                    $oldBelts                       = $systemsBodiesBeltsModel->getByRefBody($currentBody);
                    $oldRings                       = $systemsBodiesRingsModel->getByRefBody($currentBody);
                    $belts                          = array();
                    $rings                          = array();

                    foreach($message['Rings'] AS $ring)
                    {
                        $ringType = RingType::getFromFd($ring['RingClass']);

                        if(is_null($ringType))
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\Body\Ring\Type #' . $currentBody . ':' . $ring['RingClass']);
                        }
                        else
                        {
                            $ring = array(
                                'refBody'   => $currentBody,
                                'type'      => $ringType,
                                'name'      => $ring['Name'],
                                'mass'      => $ring['MassMT'],
                                'iRad'      => $ring['InnerRad'],
                                'oRad'      => $ring['OuterRad'],
                            );

                            //TODO: Handle Belt alias for insertion
                            if(stripos($ring['name'], 'ring') !== false || in_array(substr($ring['name'], -3), array(' r1', ' r2', ' r3', ' r4', ' r5')))
                            {
                                $rings[] = $ring;
                            }
                            elseif(stripos($ring['name'], 'belt') !== false)
                            {
                                $belts[] = $ring;
                            }
                            else
                            {
                                \EDSM_Api_Logger_Alias::log('Unknown ring type? #' . $currentBody . ':' . $ring['name']);
                            }
                        }
                    }

                    foreach($belts AS $belt)
                    {
                        $oldBelt = null;

                        foreach($oldBelts AS $key => $values)
                        {
                            if($values['name'] == $belt['name'])
                            {
                                unset($oldBelts[$key]);
                                $oldBelt = $values;
                                break;
                            }
                        }

                        // Update if belt was already stored
                        if(!is_null($oldBelt))
                        {
                            if(
                                   $oldBelt['type'] != $belt['type']
                                || $oldBelt['name'] != $belt['name']
                                || $oldBelt['mass'] != $belt['mass']
                                || $oldBelt['iRad'] != $belt['iRad']
                                || $oldBelt['oRad'] != $belt['oRad']
                            )
                            {
                                $systemsBodiesBeltsModel->updateById($oldBelt['id'], $belt);
                                $updateElasticStatus = true; // Force Elastic refresh in the background process
                            }
                        }
                        // Insert new belt
                        else
                        {
                            $systemsBodiesBeltsModel->insert($belt);
                            $updateElasticStatus = true; // Force Elastic refresh in the background process
                        }
                    }

                    foreach($rings AS $ring)
                    {
                        $oldRing = null;

                        foreach($oldRings AS $key => $values)
                        {
                            if($values['name'] == $ring['name'])
                            {
                                unset($oldRings[$key]);
                                $oldRing = $values;
                                break;
                            }
                        }

                        // Update if ring was already stored
                        if(!is_null($oldRing))
                        {
                            if(
                                   $oldRing['type'] != $ring['type']
                                || $oldRing['name'] != $ring['name']
                                || $oldRing['mass'] != $ring['mass']
                                || $oldRing['iRad'] != $ring['iRad']
                                || $oldRing['oRad'] != $ring['oRad']
                            )
                            {
                                $systemsBodiesRingsModel->updateById($oldRing['id'], $ring);
                                $updateElasticStatus = true; // Force Elastic refresh in the background process
                            }
                        }
                        // Insert new belt
                        else
                        {
                            $systemsBodiesRingsModel->insert($ring);
                            $updateElasticStatus = true; // Force Elastic refresh in the background process
                        }
                    }

                    // Remove remaining belts
                    if(count($oldBelts) > 0)
                    {
                        foreach($oldBelts AS $values)
                        {
                            $systemsBodiesBeltsModel->deleteById($values['id']);
                            $updateElasticStatus = true; // Force Elastic refresh in the background process
                        }
                    }

                    // Remove remaining rings
                    if(count($oldRings) > 0)
                    {
                        foreach($oldRings AS $values)
                        {
                            $systemsBodiesRingsModel->deleteById($values['id']);
                            $updateElasticStatus = true; // Force Elastic refresh in the background process
                        }
                    }

                    unset($belts, $rings);
                }
                elseif(array_key_exists('ScanType', $message) && in_array($message['ScanType'], array('Detailed', 'NavBeaconDetail')))
                {
                    $systemsBodiesBeltsModel        = new \Models_Systems_Bodies_Belts;
                    $systemsBodiesRingsModel        = new \Models_Systems_Bodies_Rings;
                    $oldBelts                       = $systemsBodiesBeltsModel->getByRefBody($currentBody);
                    $oldRings                       = $systemsBodiesRingsModel->getByRefBody($currentBody);

                    if(is_null($oldBelts) || count($oldBelts) == 0)
                    {
                        $systemsBodiesBeltsModel->deleteByRefBody($currentBody);
                        $updateElasticStatus = true; // Force Elastic refresh in the background process
                    }

                    if(is_null($oldRings) || count($oldRings) == 0)
                    {
                        $systemsBodiesRingsModel->deleteByRefBody($currentBody);
                        $updateElasticStatus = true; // Force Elastic refresh in the background process
                    }
                }

                // Do we need to update the record?
                if(count($currentBodyNewData) > 0 || count($currentBodyNewOrbitalData) > 0 || count($currentBodyNewSurfaceData) > 0 || count($currentBodyNewParentsData) > 0)
                {
                    if(count($currentBodyNewOrbitalData) > 0)
                    {
                        try
                        {
                            $currentBodyNewOrbitalData['refBody'] = $currentBody;
                            $systemsBodiesOrbitalModel->insert($currentBodyNewOrbitalData);
                        }
                        catch(\Zend_Db_Exception $e)
                        {
                            if(strpos($e->getMessage(), '1062 Duplicate') !== false)
                            {
                                unset($currentBodyNewOrbitalData['refBody']);
                                $systemsBodiesOrbitalModel->updateByRefBody($currentBody, $currentBodyNewOrbitalData);
                            }
                            else
                            {
                                if(defined('APPLICATION_SENTRY') && APPLICATION_SENTRY === true)
                                {
                                    \Sentry\captureException($e);
                                }
                            }
                        }
                    }

                    if(count($currentBodyNewSurfaceData) > 0)
                    {
                        try
                        {
                            $currentBodyNewSurfaceData['refBody'] = $currentBody;
                            $systemsBodiesSurfaceModel->insert($currentBodyNewSurfaceData);
                        }
                        catch(\Zend_Db_Exception $e)
                        {
                            if(strpos($e->getMessage(), '1062 Duplicate') !== false)
                            {
                                unset($currentBodyNewSurfaceData['refBody']);
                                $systemsBodiesSurfaceModel->updateByRefBody($currentBody, $currentBodyNewSurfaceData);
                            }
                            else
                            {
                                if(defined('APPLICATION_SENTRY') && APPLICATION_SENTRY === true)
                                {
                                    \Sentry\captureException($e);
                                }
                            }
                        }
                    }

                    if(count($currentBodyNewParentsData) > 0)
                    {
                        try
                        {
                            $currentBodyNewParentsData['refBody'] = $currentBody;
                            $systemsBodiesParentsModel->insert($currentBodyNewParentsData);
                        }
                        catch(\Zend_Db_Exception $e)
                        {
                            if(strpos($e->getMessage(), '1062 Duplicate') !== false)
                            {
                                unset($currentBodyNewParentsData['refBody']);
                                $systemsBodiesParentsModel->updateByRefBody($currentBody, $currentBodyNewParentsData);
                            }
                            else
                            {
                                if(defined('APPLICATION_SENTRY') && APPLICATION_SENTRY === true)
                                {
                                    \Sentry\captureException($e);
                                }
                            }
                        }
                    }

                    $currentBodyNewData['dateUpdated']  = $message['timestamp'];
                    $updateElasticStatus = true; // Force Elastic refresh in the background process

                    // Always update to keep track of last update
                    $systemsBodiesModel->updateById($currentBody, $currentBodyNewData);

                    if($updateElasticStatus === true)
                    {
                        $systemsBodiesInElasticModel = new \Models_Systems_Bodies_InElastic;
                        $systemsBodiesInElasticModel->deleteByRefBody(
                            $currentBody,
                            ( (array_key_exists('group', $currentBodyNewData)) ? null: $currentBodyData['group'] ),
                            ( (array_key_exists('group', $currentBodyNewData)) ? true: false )
                        );
                    }

                    if($useLogger === true && $wasInserted === false)
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Body:</span>               ' . $message['BodyName'] . ' (#' . $currentBody . ') updated.');
                    }

                    // Update Elastic! (Only if coming from EDDN...)
                    if($useLogger === true)
                    {
                        $return = \Process\Body\Elastic::insertBody($currentBody);

                        if($return === true)
                        {
                            //\EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Body:</span>               ' . $message['BodyName'] . ' (#' . $currentBody . ') updated in Elastic index.');
                        }
                        else
                        {
                            \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Body:</span>               <span class="text-danger">' . $message['BodyName'] . ' (#' . $currentBody . ') failed updating in Elastic index.</span>');
                        }
                    }
                }

                return $currentBody;
            }
        }

        return null;
    }
}