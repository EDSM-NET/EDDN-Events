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
        $currentSystem = \Component\System::getInstance($systemId);

        if($currentSystem->isValid() && $currentSystem->isHidden() === false)
        {
            $currentBody                = null;
            $systemsBodiesModel         = new \Models_Systems_Bodies;
            $systemsBodiesParentsModel  = new \Models_Systems_Bodies_Parents;
            $systemsBodiesSurfaceModel  = new \Models_Systems_Bodies_Surface;
            $systemsBodiesOrbitalModel  = new \Models_Systems_Bodies_Orbital;

            // Try to find body by name/refSystem
            if(is_null($currentBody))
            {
                $currentBody = $systemsBodiesModel->fetchRow(
                    $systemsBodiesModel->select()
                                       ->where('refSystem = ?', $currentSystem->getId())
                                       ->where('name = ?', $message['BodyName'])
                );

                if(is_null($currentBody))
                {
                    // Check name
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
                            'name'          => $message['BodyName'],
                        );

                        if(array_key_exists('BodyID', $message))
                        {
                            $insert['id64'] = $message['BodyID'];
                        }

                        try
                        {
                            $currentBody = $systemsBodiesModel->insert($insert);

                            if($useLogger === true)
                            {
                                \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Body:</span>               ' . $message['BodyName'] . ' (#' . $currentBody . ') inserted.');
                                $useLogger = false;
                            }
                        }
                        catch(\Zend_Db_Exception $e)
                        {
                            // Based on unique index, this body entry was already saved.
                            if(strpos($e->getMessage(), '1062 Duplicate') !== false)
                            {
                                $currentBody = $systemsBodiesModel->fetchRow(
                                    $systemsBodiesModel->select()
                                                       ->where('refSystem = ?', $currentSystem->getId())
                                                       ->where('name = ?', $message['BodyName'])
                                );
                                $currentBody = $currentBody->id;
                            }
                            else
                            {
                                $registry = \Zend_Registry::getInstance();

                                if($registry->offsetExists('sentryClient'))
                                {
                                    $sentryClient = $registry->offsetGet('sentryClient');
                                    $sentryClient->captureException($e);
                                }
                            }
                        }
                    }
                    else
                    {
                        // Belt cluster are just useless, we rely on belts sent with the parent body!
                        /*
                        $registry = \Zend_Registry::getInstance();

                        if($registry->offsetExists('sentryClient'))
                        {
                            $sentryClient = $registry->offsetGet('sentryClient');
                            $sentryClient->captureMessage(
                                'Belt Cluster',
                                array('currentSystem' => $currentSystem),
                                array('extra' => $message,)
                            );
                        }
                        */
                    }
                }
                else
                {
                    $currentBody = $currentBody->id;
                }
            }

            if(!is_null($currentBody))
            {
                $currentBodyData            = $systemsBodiesModel->getById($currentBody);
                $currentBodyNewData         = array();

                $currentBodyParentsData     = $systemsBodiesParentsModel->getByRefBody($currentBody);
                $currentBodyNewParentsData  = array();

                $currentBodySurfaceData     = $systemsBodiesSurfaceModel->getByRefBody($currentBody);
                $currentBodyNewSurfaceData  = array();

                $currentBodyOrbitalData     = $systemsBodiesOrbitalModel->getByRefBody($currentBody);
                $currentBodyNewOrbitalData  = array();

                if(array_key_exists('BodyID', $message))
                {
                    if(is_null($currentBodyData['id64']) || $currentBodyData['id64'] != $message['BodyID'])
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

                        $registry = \Zend_Registry::getInstance();

                        if($registry->offsetExists('sentryClient'))
                        {
                            $sentryClient = $registry->offsetGet('sentryClient');
                            $sentryClient->captureException($e);
                        }
                    }

                    if(!is_null($message['Parents']) && (is_null($currentBodyParentsData) || $message['Parents'] != $currentBodyParentsData['parents']))
                    {
                        $currentBodyNewParentsData['parents'] = $message['Parents'];
                    }
                }

                if(array_key_exists('StarType', $message))
                {
                    if($currentBodyData['group'] != 1)
                    {
                        $currentBodyNewData['group'] = 1;
                    }

                    if(array_key_exists('StarType', $message))
                    {
                        $starType = StarType::getFromFd($message['StarType']);

                        if(is_null($starType))
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\Body\Star\Type #' . $currentBody . ':' . $message['StarType']);
                        }

                        if($starType != $currentBodyData['type'])
                        {
                            $currentBodyNewData['type'] = $starType;
                        }
                    }

                    if(array_key_exists('Luminosity', $message) && $message['Luminosity'] != $currentBodyData['luminosity'])
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
                    if($currentBodyData['group'] != 2)
                    {
                        $currentBodyNewData['group'] = 2;
                    }

                    if(array_key_exists('PlanetClass', $message))
                    {
                        $planetType = PlanetType::getFromFd($message['PlanetClass']);

                        if(is_null($planetType))
                        {
                            \EDSM_Api_Logger_Alias::log('Alias\Body\Planet\Type #' . $currentBody . ':' . $message['PlanetClass']);
                        }

                        if($planetType != $currentBodyData['type'])
                        {
                            $currentBodyNewData['type'] = $planetType;
                        }
                    }

                    if(array_key_exists('TidalLock', $message))
                    {
                        if($message['TidalLock'] == true && (is_null($currentBodyOrbitalData) || $currentBodyOrbitalData['rotationalPeriodTidallyLocked'] != 1))
                        {
                            $currentBodyNewOrbitalData['rotationalPeriodTidallyLocked'] = 1;
                        }
                        elseif(is_null($currentBodyOrbitalData))
                        {
                            $currentBodyNewOrbitalData['rotationalPeriodTidallyLocked'] = 0;
                        }
                    }

                    if(array_key_exists('Landable', $message))
                    {
                        if($message['Landable'] == true && $currentBodyData['isLandable'] != 1)
                        {
                            $currentBodyNewData['isLandable'] = 1;
                        }
                        elseif($currentBodyData['isLandable'] != 0)
                        {
                            $currentBodyNewData['isLandable'] = 0;
                        }
                    }

                    if(array_key_exists('Materials', $message) && count($message['Materials']) > 0)
                    {
                        // Force landable ;)
                        if($currentBodyData['isLandable'] != 1)
                        {
                            $currentBodyNewData['isLandable'] = 1;
                        }
                    }
                }

                // General variables
                if(array_key_exists('DistanceFromArrivalLS', $message))
                {
                    if(is_null($currentBodyData['distanceToArrival']))
                    {
                        $currentBodyNewData['distanceToArrival'] = $message['DistanceFromArrivalLS'];
                    }
                    elseif($message['DistanceFromArrivalLS'] != $currentBodyData['distanceToArrival'])
                    {
                        if(abs($message['DistanceFromArrivalLS'] - $currentBodyData['distanceToArrival']) > 5)
                        {
                            $currentBodyNewData['distanceToArrival'] = $message['DistanceFromArrivalLS'];
                        }
                    }
                }

                // Surface variables
                if(array_key_exists('Radius', $message) && (is_null($currentBodySurfaceData) || $message['Radius'] != $currentBodySurfaceData['radius']))
                {
                    $currentBodyNewSurfaceData['radius'] = $message['Radius'];
                }
                if(array_key_exists('SurfaceTemperature', $message) && (is_null($currentBodySurfaceData) || $message['SurfaceTemperature'] != $currentBodySurfaceData['surfaceTemperature']))
                {
                    $currentBodyNewSurfaceData['surfaceTemperature'] = $message['SurfaceTemperature'];
                }

                if(array_key_exists('StarType', $message))
                {
                    if(array_key_exists('Age_MY', $message) && (is_null($currentBodySurfaceData) || $message['Age_MY'] != $currentBodySurfaceData['age']) || is_null($currentBodySurfaceData['age']))
                    {
                        $currentBodyNewSurfaceData['age'] = $message['Age_MY'];
                    }

                    if(array_key_exists('StellarMass', $message) && (is_null($currentBodySurfaceData) || $message['StellarMass'] != $currentBodySurfaceData['mass']))
                    {
                        $currentBodyNewSurfaceData['mass'] = $message['StellarMass'];
                    }
                }
                elseif(array_key_exists('PlanetClass', $message))
                {
                    if(array_key_exists('MassEM', $message) && (is_null($currentBodySurfaceData) || $message['MassEM'] != $currentBodySurfaceData['mass']))
                    {
                        $currentBodyNewSurfaceData['mass'] = $message['MassEM'];
                    }

                    if(array_key_exists('SurfacePressure', $message) && (is_null($currentBodySurfaceData) || $message['SurfacePressure'] != $currentBodySurfaceData['surfacePressure']))
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

                        if(is_null($currentBodySurfaceData) || $prefix != $currentBodySurfaceData['atmospherePrefix'])
                        {
                            $currentBodyNewSurfaceData['atmospherePrefix'] = $prefix;
                        }
                        if(is_null($currentBodySurfaceData) || $atmosphere != $currentBodySurfaceData['atmosphereType'])
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

                        if(is_null($currentBodySurfaceData) || $prefix != $currentBodySurfaceData['volcanismPrefix'])
                        {
                            $currentBodyNewSurfaceData['volcanismPrefix'] = $prefix;
                        }
                        if(is_null($currentBodySurfaceData) || $volcanism != $currentBodySurfaceData['volcanismType'])
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
                            if(is_null($currentBodySurfaceData) || $terraformState != $currentBodySurfaceData['terraformingState'])
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
                if(array_key_exists('RotationPeriod', $message) && (is_null($currentBodyOrbitalData) || $message['RotationPeriod'] != $currentBodyOrbitalData['rotationalPeriod']))
                {
                    $currentBodyNewOrbitalData['rotationalPeriod'] = $message['RotationPeriod'];
                }
                if(array_key_exists('SemiMajorAxis', $message) && (is_null($currentBodyOrbitalData) || $message['SemiMajorAxis'] != $currentBodyOrbitalData['semiMajorAxis']))
                {
                    $currentBodyNewOrbitalData['semiMajorAxis'] = $message['SemiMajorAxis'];
                }
                if(array_key_exists('Eccentricity', $message) && (is_null($currentBodyOrbitalData) || $message['Eccentricity'] != $currentBodyOrbitalData['orbitalEccentricity']))
                {
                    $currentBodyNewOrbitalData['orbitalEccentricity'] = $message['Eccentricity'];
                }
                if(array_key_exists('OrbitalInclination', $message) && (is_null($currentBodyOrbitalData) || $message['OrbitalInclination'] != $currentBodyOrbitalData['orbitalInclination']))
                {
                    $currentBodyNewOrbitalData['orbitalInclination'] = $message['OrbitalInclination'];
                }
                if(array_key_exists('Periapsis', $message) && (is_null($currentBodyOrbitalData) || $message['Periapsis'] != $currentBodyOrbitalData['argOfPeriapsis']))
                {
                    $currentBodyNewOrbitalData['argOfPeriapsis'] = $message['Periapsis'];
                }
                if(array_key_exists('OrbitalPeriod', $message) && (is_null($currentBodyOrbitalData) || $message['OrbitalPeriod'] != $currentBodyOrbitalData['orbitalPeriod']))
                {
                    $currentBodyNewOrbitalData['orbitalPeriod'] = $message['OrbitalPeriod'];
                }
                if(array_key_exists('AxialTilt', $message) && (is_null($currentBodyOrbitalData) || $message['AxialTilt'] != $currentBodyOrbitalData['axisTilt']))
                {
                    $currentBodyNewOrbitalData['axisTilt'] = $message['AxialTilt'];
                }

                // Reserve Level
                if(array_key_exists('ReserveLevel', $message))
                {
                    $reserveLevel = ReserveLevel::getFromFd($message['ReserveLevel']);

                    if(!is_null($reserveLevel))
                    {
                        if($currentBodyData['reserveLevel'] != $reserveLevel)
                        {
                            $currentBodyNewData['reserveLevel'] = $reserveLevel;
                        }
                    }
                    else
                    {
                        \EDSM_Api_Logger_Alias::log('Alias\Body\Planet\ReserveLevel #' . $currentBody . ':' . $message['ReserveLevel']);
                    }
                }

                // Do we need to update the record?
                if(count($currentBodyNewData) > 0)
                {
                    if(strtotime($message['timestamp']) > strtotime($currentBodyData['dateUpdated']))
                    {
                        $currentBodyNewData['dateUpdated'] = $message['timestamp'];
                    }

                    $systemsBodiesModel->updateById(
                        $currentBody,
                        $currentBodyNewData
                    );

                    if($useLogger === true)
                    {
                        \EDSM_Api_Logger::log('<span class="text-info">EDDN\System\Body:</span>               ' . $message['BodyName'] . ' (#' . $currentBody . ') updated.');
                    }
                }

                if(count($currentBodyNewOrbitalData) > 0)
                {
                    if(is_null($currentBodyOrbitalData))
                    {
                        $currentBodyNewOrbitalData['refBody'] = $currentBody;

                        try
                        {
                            $systemsBodiesOrbitalModel->insert($currentBodyNewOrbitalData);
                        }
                        catch(\Zend_Db_Exception $e)
                        {
                            if(strpos($e->getMessage(), '1062 Duplicate') !== false)
                            {
                                // Do nothing, and expect the other message to have done his job!
                            }
                            else
                            {
                                $registry = \Zend_Registry::getInstance();

                                if($registry->offsetExists('sentryClient'))
                                {
                                    $sentryClient = $registry->offsetGet('sentryClient');
                                    $sentryClient->captureException($e);
                                }
                            }
                        }
                    }
                    else
                    {
                        $systemsBodiesOrbitalModel->updateByRefBody($currentBody, $currentBodyNewOrbitalData);
                    }
                }

                if(count($currentBodyNewSurfaceData) > 0)
                {
                    if(is_null($currentBodySurfaceData))
                    {
                        $currentBodyNewSurfaceData['refBody'] = $currentBody;

                        try
                        {
                            $systemsBodiesSurfaceModel->insert($currentBodyNewSurfaceData);
                        }
                        catch(\Zend_Db_Exception $e)
                        {
                            if(strpos($e->getMessage(), '1062 Duplicate') !== false)
                            {
                                // Do nothing, and expect the other message to have done his job!
                            }
                            else
                            {
                                $registry = \Zend_Registry::getInstance();

                                if($registry->offsetExists('sentryClient'))
                                {
                                    $sentryClient = $registry->offsetGet('sentryClient');
                                    $sentryClient->captureException($e);
                                }
                            }
                        }
                    }
                    else
                    {
                        $systemsBodiesSurfaceModel->updateByRefBody($currentBody, $currentBodyNewSurfaceData);
                    }
                }

                if(count($currentBodyNewParentsData) > 0)
                {
                    if(is_null($currentBodyParentsData))
                    {
                        $currentBodyNewParentsData['refBody'] = $currentBody;

                        try
                        {
                            $systemsBodiesParentsModel->insert($currentBodyNewParentsData);
                        }
                        catch(\Zend_Db_Exception $e)
                        {
                            if(strpos($e->getMessage(), '1062 Duplicate') !== false)
                            {
                                // Do nothing, and expect the other message to have done his job!
                            }
                            else
                            {
                                $registry = \Zend_Registry::getInstance();

                                if($registry->offsetExists('sentryClient'))
                                {
                                    $sentryClient = $registry->offsetGet('sentryClient');
                                    $sentryClient->captureException($e);
                                }
                            }
                        }
                    }
                    else
                    {
                        $systemsBodiesParentsModel->updateByRefBody($currentBody, $currentBodyNewParentsData);
                    }
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
                            }
                            catch(\Zend_Db_Exception $e)
                            {
                                // Based on unique index, this entry was already saved.
                                if(strpos($e->getMessage(), '1062 Duplicate') !== false)
                                {

                                }
                                else
                                {
                                    $registry = \Zend_Registry::getInstance();

                                    if($registry->offsetExists('sentryClient'))
                                    {
                                        $sentryClient = $registry->offsetGet('sentryClient');
                                        $sentryClient->captureException($e);
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
                        }
                    }

                    unset($composition, $oldComposition);
                }
                elseif(array_key_exists('ScanType', $message) && in_array($message['ScanType'], array('Detailed', 'NavBeaconDetail')))
                {
                    $systemsBodiesAtmosphereCompositionModel = new \Models_Systems_Bodies_AtmosphereComposition;
                    $systemsBodiesAtmosphereCompositionModel->deleteByRefBody($currentBody);
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
                            $composition[$componentType] = round($qty * 10000);
                        }
                    }

                    foreach($composition AS $type => $qty)
                    {
                        $oldComponent = null;

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
                            }
                            catch(\Zend_Db_Exception $e)
                            {
                                // Based on unique index, this entry was already saved.
                                if(strpos($e->getMessage(), '1062 Duplicate') !== false)
                                {

                                }
                                else
                                {
                                    $registry = \Zend_Registry::getInstance();

                                    if($registry->offsetExists('sentryClient'))
                                    {
                                        $sentryClient = $registry->offsetGet('sentryClient');
                                        $sentryClient->captureException($e);
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
                        }
                    }

                    unset($composition, $oldComposition);
                }
                elseif(array_key_exists('ScanType', $message) && in_array($message['ScanType'], array('Detailed', 'NavBeaconDetail')))
                {
                    $systemsBodiesSolidCompositionModel = new \Models_Systems_Bodies_SolidComposition;
                    $systemsBodiesSolidCompositionModel->deleteByRefBody($currentBody);
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
                        $oldMaterial = null;

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
                            if($oldMaterial['qty'] != $qty)
                            {
                                $systemsBodiesMaterialsModel->updateByRefBodyAndRefMaterial($currentBody, $oldMaterial['refMaterial'], array(
                                    'qty'           => $qty,
                                    'percent'       => round($qty * 100),
                                ));
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
                                    'qty'           => $qty,
                                    'percent'       => round($qty * 100),
                                ));
                            }
                            catch(\Zend_Db_Exception $e)
                            {
                                // Based on unique index, this entry was already saved.
                                if(strpos($e->getMessage(), '1062 Duplicate') !== false)
                                {

                                }
                                else
                                {
                                    $registry = \Zend_Registry::getInstance();

                                    if($registry->offsetExists('sentryClient'))
                                    {
                                        $sentryClient = $registry->offsetGet('sentryClient');
                                        $sentryClient->captureException($e);
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
                        }
                    }

                    unset($materials);
                }
                elseif(array_key_exists('ScanType', $message) && in_array($message['ScanType'], array('Detailed', 'NavBeaconDetail')))
                {

                    $systemsBodiesMaterialsModel    = new \Models_Systems_Bodies_Materials;
                    $systemsBodiesMaterialsModel->deleteByRefBody($currentBody);
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
                            }
                        }
                        // Insert new belt
                        else
                        {
                            $systemsBodiesBeltsModel->insert($belt);
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
                            }
                        }
                        // Insert new belt
                        else
                        {
                            $systemsBodiesRingsModel->insert($ring);
                        }
                    }

                    // Remove remaining belts
                    if(count($oldBelts) > 0)
                    {
                        foreach($oldBelts AS $values)
                        {
                            $systemsBodiesBeltsModel->deleteById($values['id']);
                        }
                    }

                    // Remove remaining rings
                    if(count($oldRings) > 0)
                    {
                        foreach($oldRings AS $values)
                        {
                            $systemsBodiesRingsModel->deleteById($values['id']);
                        }
                    }

                    unset($belts, $rings);
                }
                elseif(array_key_exists('ScanType', $message) && in_array($message['ScanType'], array('Detailed', 'NavBeaconDetail')))
                {
                    $systemsBodiesBeltsModel        = new \Models_Systems_Bodies_Belts;
                    $systemsBodiesBeltsModel->deleteByRefBody($currentBody);
                    $systemsBodiesRingsModel        = new \Models_Systems_Bodies_Rings;
                    $systemsBodiesRingsModel->deleteByRefBody($currentBody);
                }

                return $currentBody;
            }
        }

        return null;
    }
}