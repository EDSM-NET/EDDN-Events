<?php
/**
 * Elite Dangerous Star Map
 * @link https://www.edsm.net/
 */

namespace   EDDN\Station;

use         Alias\Station\Service;

class Services
{
    static public function handle($stationId, $message)
    {
        $currentStation = \EDSM_System_Station::getInstance($stationId);
        
        if($currentStation->isValid())
        {
            $servicesLastUpdate = $currentStation->getServicesLastUpdate();
            
            if(!is_null($servicesLastUpdate) && strtotime($message['timestamp']) < strtotime($servicesLastUpdate))
            {
                \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Services:</span>          <span class="text-danger">' . $currentStation->getName() . ' (#' . $currentStation->getId() . ') services too old (' . $message['timestamp'] . ').</span>');
                return;
            }
            
            if(array_key_exists('StationServices', $message))
            {
                $stationsModel              = new \Models_Stations;
                $stationsServicesModel      = new \Models_Stations_Services;
                
                $newServices                = array();
                $nbUpdates                  = 0;
                $updateLastUpdate           = false;
                
                // Create list of services with ID
                foreach($message['StationServices'] AS $service)
                {
                    $serviceId = Service::getFromFd($service);
                    
                    if(is_null($serviceId))
                    {
                        \EDSM_Api_Logger_Alias::log('Alias\Station\Service #' . $currentStation->getId() . ':' . $service);
                    }
                    else
                    {
                        $newServices[] = $serviceId;
                    }
                }
                
                $oldServices = $stationsServicesModel->getByRefStation($currentStation->getId());
                
                // Delete services not in the station anymore
                if(!is_null($oldServices) && count($oldServices) > 0)
                {
                    foreach($oldServices AS $oldService)
                    {
                        // Update
                        if(in_array($oldService['refService'], $newServices))
                        {
                            $nbUpdates++;
                            
                            unset($newServices[array_search($oldService['refService'], $newServices)]);
                        }
                        else
                        {
                            \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Services:</span>              - Delete ' . Service::get($oldService['refService']));
                            
                            $stationsServicesModel->deleteById($oldService['id']);
                            
                            // Delete commodities from the old market
                            if($oldService['refService'] == 1)
                            {
                                $stationsCommoditiesModel = new \Models_Stations_Commodities;
                                $stationsCommoditiesModel->deleteByRefStation($currentStation->getId());
                                
                                $stationsModel->updateById($currentStation->getId(), ['marketUpdateTime' => new \Zend_Db_Expr('NULL')]);
                                
                                unset($stationsCommoditiesModel);
                            }
                            
                            // Delete ships from the old shipyard
                            if($oldService['refService'] == 2)
                            {
                                $stationsShipsModel = new \Models_Stations_Ships;
                                $stationsShipsModel->deleteByRefStation($currentStation->getId());
                                
                                $stationsModel->updateById($currentStation->getId(), ['shipyardUpdateTime' => new \Zend_Db_Expr('NULL')]);
                                
                                unset($stationsShipsModel);
                            }
                            
                            // Delete outfittings from the old outfitting
                            if($oldService['refService'] == 3)
                            {
                                $stationsOutfittingsModel = new \Models_Stations_Outfittings;
                                $stationsOutfittingsModel->deleteByRefStation($currentStation->getId());
                                
                                $stationsModel->updateById($currentStation->getId(), ['outfittingUpdateTime' => new \Zend_Db_Expr('NULL')]);
                                
                                unset($stationsOutfittingsModel);
                            }
                            
                            $updateLastUpdate = true;
                        }
                    }
                }
                
                // Add new services
                foreach($newServices AS $newService)
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Services:</span>              - Add ' . Service::get($newService));
                    
                    $stationsServicesModel->insert(array(
                        'refStation'    => $currentStation->getId(),
                        'refService'    => $newService
                    ));
                    
                    $updateLastUpdate = true;
                }
                
                if($nbUpdates > 0)
                {
                    \EDSM_Api_Logger::log('<span class="text-info">EDDN\Station\Services:</span>          Updated ' . \Zend_Locale_Format::toNumber($nbUpdates) . ' services');
                }
                
                if($updateLastUpdate === true)
                {
                    $stationsModel->updateById($currentStation->getId(), ['servicesUpdateTime' => $message['timestamp']]);
                }
                
                unset($stationsModel, $stationsServicesModel);
            }
        }
    }
}