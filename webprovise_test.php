<?php
const TRAVEL_LIST_API = 'https://5f27781bf5d27e001612e057.mockapi.io/webprovise/travels';
const COMPANY_LIST_API = 'https://5f27781bf5d27e001612e057.mockapi.io/webprovise/companies';
class Travel
{
    /**
     * get Travel data
     * @return mixed
     */
    private function getTravelList()
    {
        return (new Helper)->doGetData(TRAVEL_LIST_API);
    }

    /**
     * Make Travel list by Company ID
     * @param $company_ids
     * @return array
     */
    public function makeTravelListByCompanyId($company_ids): array
    {
        $travels = $this->getTravelList();
        $result = [];
        foreach ($travels as $travel) {
            foreach ($company_ids as $id) {
                if ($travel['companyId'] == $id) {
                    $result[$id][] = $travel;
                }
            }
        }
        return $result;
    }
}

class Company
{
    /**
     * get Company data
     * @return mixed
     */
    public function getCompanyList()
    {
        return (new Helper)->doGetData(COMPANY_LIST_API);
    }

    /**
     * Get an unique array of Company IDs to process calculations
     * @param $companies
     * @return array
     */
    public function getCompanyIds($companies): array
    {
        $company_ids = [];
        foreach ($companies as $company) {
            $company_ids[] = $company['id'];
        }
        return array_unique($company_ids);
    }

    /**
     * Get Company data by a given ID
     * @param $id
     * @param $company_list
     * @return false|mixed
     */
    public function getCompanyDataById($id, $company_list)
    {
        foreach ($company_list as $company) {
            if ($company['id'] == $id) {
                return $company;
            }
        }
        return false;
    }
}

class TestScript
{
    /**
     * Execute the Test
     * @return void
     */
    public function execute()
    {
        $start = microtime(true);
        $company_model = new Company();
        $travel_model = new Travel();
        $helper = new Helper();
        $companies = $company_model->getCompanyList();
        $company_ids = $company_model->getCompanyIds($companies);
        $travel_list = $travel_model->makeTravelListByCompanyId($company_ids);
        $time_to_retrieve_data = (microtime(true) - $start);

        $result = $helper->buildCompanyTreeWithAssociatedTravelCost($travel_list, $company_model, $companies);
        echo 'Raw result: <br>';
        echo json_encode($result);
        //the output json string looks confusing so I'm adding a beautified block
        echo '<hr>';
        echo 'Result Beautified: <br>';
        echo '<pre>';
        print_r($result);
        echo '</pre>';
        echo '<hr>';
        //time consumption should be split into 2 pieces, the data retrieving time affected by internet connection and the calculation time on local machine
        echo 'Data Retrieving time: ' . $time_to_retrieve_data . 's<br>';
        echo 'Calculation time: ' . (microtime(true) - $start - $time_to_retrieve_data) . 's';
    }
}

class Helper
{
    /**
     * Make CURL request to retrieve data from a given source
     * @param $url
     * @return bool|string
     */
    public function doGetData($url)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        return json_decode($response, true);
    }

    /**
     * Generate multidimensional array to display Company tree from flat data
     * @param $flat_array
     * @param $root
     * @return array
     */
    public function createTree($flat_array, $root = 0): array
    {
        $parents = array();
        foreach ($flat_array as $a) {
            $parents[$a['parentId']][] = $a;
        }
        return $this->createBranch($parents, $parents[$root]);
    }

    /**
     * Create tree branches recursively
     * @param $parents
     * @param $children
     * @return array
     */
    public function createBranch(&$parents, $children): array
    {
        $tree = array();
        foreach ($children as $child) {
            if (isset($parents[$child['id']])) {
                $child['children'] = $this->createBranch($parents, $parents[$child['id']]);
            }
            $tree[] = $child;
        }
        return $tree;
    }

    /**
     * Build the Company tree with associated travel cost
     * @param array $travel_list
     * @param Company $company_model
     * @param $companies
     * @param Helper $helper
     * @return array
     */
    public function buildCompanyTreeWithAssociatedTravelCost(array $travel_list, Company $company_model, $companies): array
    {
        $cost_list = $this->buildListOfTotalTravelCostByCompanyIDs($travel_list, $company_model, $companies);
        $sum_cost = $this->getSumCost($cost_list);
        $cost_list = $this->getCostList($sum_cost, $cost_list);
        return $this->createTree($cost_list);
    }

    /**
     * build a list of total travel cost by Company IDs
     * @param array $travel_list
     * @param Company $company_model
     * @param $companies
     * @return array
     */
    protected function buildListOfTotalTravelCostByCompanyIDs(array $travel_list, Company $company_model, $companies): array
    {
        $cost_list = [];
        foreach ($travel_list as $company_id => $trips) {
            $company_data = $company_model->getCompanyDataById($company_id, $companies);
            $cost_list[$company_id] = $company_data ?? null;
            $cost_list[$company_id]['cost'] = array_sum(array_column($trips, 'price'));
            unset($cost_list[$company_id]['createdAt']);//unset the unnecessary data
        }
        return $cost_list;
    }

    /**
     * sum the travel cost of a company and all of its child companies
     * @param array $cost_list
     * @return array
     */
    protected function getSumCost(array $cost_list): array
    {
        $sum_cost = [];
        foreach ($cost_list as $item) {
            if (isset($item['parentId'])) {
                if (isset($sum_cost[$item['parentId']])) {
                    $sum_cost[$item['parentId']] += $item['cost'];
                } else {
                    $sum_cost[$item['parentId']] = $item['cost'];
                }
            }

        }
        $sum_cost[0] = array_sum($sum_cost);
        return $sum_cost;//Sum travel cost of Bid Daddy means the total travel cost itself
    }

    /**
     * assign summary of travel cost to each corresponding company regardless of its place in the hierarchy
     * @param array $sum_cost
     * @param array $cost_list
     * @return array
     */
    protected function getCostList(array $sum_cost, array $cost_list): array
    {
        foreach ($sum_cost as $key => $sum) {
            if (array_key_exists($key, $cost_list)) {
                $cost_list[$key]['cost'] = $sum;
                //special case for Big Daddy
                if ($cost_list[$key]['parentId'] == 0) {
                    $cost_list[$key]['cost'] = $sum_cost[0];
                }
            }
        }
        return $cost_list;
    }
}

(new TestScript())->execute();