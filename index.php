<?php
require_once 'curl-master/curl.php';
require_once 'phpquery-master/phpQuery/phpQuery.php';

class HotlineParser
{
    const HOTLINE = 'http://hotline.ua';
    const LAPTOPS_CATEGORY_SLUG = 'computer/noutbuki-netbuki';
    const SCREEN_DIAGONAL = '883';
    const VOLUME_SSD = '11864-11865';
    const PRICE = '11866-11867-85763-85764';
    const PAGE_GET_PARAMETER = 'p=';

    const PRODUCTS_CSS_CLASS = '.cell .gd-promo-brdr';
    const PRODUCTS_TITLE_CSS_CLASS = '.m_r-10 .g_statistic';
    const PRODUCTS_PRICE_CSS_CLASS = '.text-13-480';
    const PRODUCTS_IMAGE_CSS_CLASS = '.max-120';
    const PRODUCTS_IMAGE_SRC = 'src';

    const NO_PRODUCTS_ON_PAGE_MESSAGE_TEMPLATE = '/в данный момент товары отcутствуют/';

    private $i = 0;
    private $parsedData = array();

    public function main()
    {
        while (true) {

            $response = $this->_getProductsPage();

            if ($this->_isNoProductsOnPage($response)) {
                break;
            }

            $this->_doIncrementIterator();
            $doc = phpQuery::newDocument($response->body);
            $products = $doc->find(self::PRODUCTS_CSS_CLASS);

            foreach ($products as $product) {
                $productParams = $this->_getProductParams($product);

                $this->setProductAttributes(
                    $productParams['title'],
                    $productParams['price'],
                    $productParams['image']
                );
            }
        }

        $this->_doRemoveProductsWithoutPrice();
        $this->_doSortProductsByPrice();
        $laptops = $this->_getLaptopsWithLowestPrices();

        $HotlineParserDB = new HotlineParserDB();

        foreach ($laptops as $laptopName => $laptopParams) {
            $HotlineParserDB->insertLaptops(
                $laptopName,
                $laptopParams['imageLink']
            );
        }
        return true;
    } // end main

    private function _getProductParams($product)
    {
        $pq = pq($product);
        $productName = $this->_getPreparedName(
            $pq->find(self::PRODUCTS_TITLE_CSS_CLASS)->text()
        );
        $productPrice = $this->_getPreparedPrice(
            $pq->find(self::PRODUCTS_PRICE_CSS_CLASS)->text()
        );
        $productImageLink = $this-> _getPreparedImageLink(
            $pq->find(self::PRODUCTS_IMAGE_CSS_CLASS)->attr(self::PRODUCTS_IMAGE_SRC)
        );

        $result = array(
            'title' => $productName,
            'price' => $productPrice,
            'image' => $productImageLink
        );
        return $result;

    }


    private function _getProductsPage()
    {
        $curl = new Curl();
        $response =$curl->get(
            self::HOTLINE.'/'.
            self::LAPTOPS_CATEGORY_SLUG.'/'.
            self::SCREEN_DIAGONAL.'-'.
            self::VOLUME_SSD.'-'.
            self::PRICE. '?'.
            self::PAGE_GET_PARAMETER.
            $this->i
        );
        return $response;
    }


    private function _getLaptopsWithLowestPrices()
    {
        return array_splice($this->parsedData, 0, 15);
    } // end _getLaptopsWithLowestPrices

    private function _doSortProductsByPrice()
    {
        $price_sort = array();
        foreach (($this->parsedData) as $key => $arr) {
            $price_sort[$key] = $arr['price'];
        }
        array_multisort($price_sort, SORT_ASC, $this->parsedData);
    } // end _doSortProductsByPrice

    private function _doRemoveProductsWithoutPrice()
    {
        $productsWithoutPrice = $this->_getProductsWithoutPrice($this->parsedData);
        foreach ($productsWithoutPrice as $productWithoutPriceName) {
            unset($this->parsedData[$productWithoutPriceName]);
        }

        return true;
    } // end _doRemoveProductsWithoutPrice

    private function _getProductsWithoutPrice($products)
    {
        $result = array();
        foreach ($products as $productName => $productAttributes) {
            if ($productAttributes['price'] == '') {
                $result[$productName] = $productName;
            }
        }

        return $result;
    } // end _getProductsWithoutPrice

    public function setProductAttributes($name, $price, $imageLink)
    {
        $this->parsedData[$name] = array(
            "price" => $price,
            "imageLink" => $imageLink
        );

        return true;
    } // end setProductAttributes

    private function _getPreparedName($name)
    {
        return trim($name);
    } // end _getPreparedName

    private function _getPreparedPrice($price)
    {
        return preg_replace("/[^0-9]/", '', $price);
    } // end

    private function _getPreparedImageLink($imageLink)
    {
        if (!$imageLink) {
            return false;
        }
        return self::HOTLINE.$imageLink;
    } // end _getPreparedImg

    private function _isNoProductsOnPage($pageText)
    {
        return (bool) preg_match(self::NO_PRODUCTS_ON_PAGE_MESSAGE_TEMPLATE, $pageText);
    } // end _isNoProductsOnPage

    private function _doIncrementIterator()
    {
        $this->i++;
        return true;
    } // end _doIncrementIterator
}

class HotlineParserDB
{
    public function insertLaptops($title, $image)
    {
        $conn = $this->_doConnectToDB();
        $stmt = $conn->prepare("INSERT INTO laptops (title, image) VALUES (:title, :image)");
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':image', $image);
        $stmt->execute();
    } // end insertLaptop
    
    private function _doConnectToDB()
    {
        $servername = "localhost";
        $username = "root";
        $password = "";
        $dbname = "hotlineparser";

        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $conn;
    }
}

$hotlineParser = new HotlineParser();
$hotlineParser->main();