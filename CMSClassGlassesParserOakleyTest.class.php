<?php

require_once('./engine/simple_html_dom.php');

class CMSClassGlassesParserOakleyTest extends CMSClassGlassesParser {
    const URL_BASE = "https://1242.ovault.com";
    // страница логина
    const URL_LOGIN = 'https://1242.ovault.com/oakb2b/b2b/init.do';
    // адрес логина
    const URL_LOGIN_DO = "https://1242.ovault.com/oakb2b/user/login.do";
    // Для получения фрейма хедера.
    // Используется для проверки логина
    const URL_HEADER = "https://1242.ovault.com/oakb2b/b2b/z_header.do";
    const URL_CATEGORY = "https://1242.ovault.com/oakb2b/b2b/showCatalog.do";


    private $count_items = 0;
    private $count_variations = 0;

    /**
     * @return int
     */
    public function getProviderId() {
        return CMSLogicProvider::OAKLEY;
    }

    /**
     * Для входа на сайт
     */
    public function doLogin() {
        $http = $this->getHttp();
        // $http = $this->getHttp1();

        // сначала получаем страницу логина с которой потом ничего не делаем
        // иначе не логинится
        $http->doGet(self::URL_LOGIN);

        $post = array(
            'UserId' => $this->getUsername(),
            'language' => 'en',
            'language_override' => '',
            'login' => 'LOG IN',
            'nolog_password' => $this->getPassword()

        );

        $http->doPost(self::URL_LOGIN_DO, $post);
        // получаем хедер страницы для подтверждения авторизации
        $http->doGet(self::URL_HEADER);

    }

    /**
     * Для логина, так как защищенное соединение
     * (пока не используется так как у них у самих проблемы из сертификатом)
     * @return CMSPluginHttp
     */
    public function getHttp1() {
        $this->getHttp();

        curl_setopt($this->getHttp()->getCurl(), CURLOPT_SSLVERSION,3);
        curl_setopt($this->getHttp()->getCurl(), CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($this->getHttp()->getCurl(), CURLOPT_CAINFO, CMSConfig::get('plugin_http::ssl_sertificate_oakley'));
        curl_setopt($this->getHttp()->getCurl(), CURLOPT_VERBOSE, true);
        curl_setopt($this->getHttp()->getCurl(), CURLOPT_CERTINFO, true);

        return $this->getHttp();
    }

    /**
     * Проверка логина
     * @param  string  $contents
     * @return boolean
     */
    public function isLoggedIn($contents) {
        return stripos($contents, '<span>Log off</span>') !== false;
    }

    /**
     * Для синхронизации брендов (в данном случае одного)
     */
    public function doSyncBrands() {
        $brands = array();
        $coded = array();

        $brands[1] = array('name' => 'Oakley');

        if (!$brands) {
            throw new CMSException();
        }

        $myBrands = CMSLogicBrand::getInstance()->getAll($this->getProvider());

        foreach($myBrands as $b) {
            if ($b instanceof CMSTableBrand) {
                $coded[$b->getCode()] = $b;
            }
        }

        foreach($brands as $code => $info) {
            if (!isset($coded[$code])) {
                CMSLogicBrand::getInstance()->create($this->getProvider(), $info['name'], $code, '');
            } else {
                echo "Brand {$info['name']} already isset.\n";
            }
        }
    }

    /**
     * Синхронизация моделей
     */
    public function doSyncItems() {
        $brands = CMSLogicBrand::getInstance()->getAll($this->getProvider());

        // так как бренд один то берем первый элемент из массива
        $brand = current($brands);

        if($brand instanceof CMSTableBrand) {
            if($brand->getValid()) {
                echo get_class($this), ': syncing items of brand: [', $brand->getId(), '] ', $brand->getTitle(), "\n";
            } else {
                echo get_class($this), ': SKIP! syncing items of Disabled brand: [', $brand->getId(), '] ', $brand->getTitle(), "\n";
                return;
            }
        } else {
            throw new Exception("Brand mast be an instance CMSTableBrand!");
        }

        // Сбрасываем is_valid для моделей бренда - флаг наличия модели у провайдера
        $this->resetModelByBrand($brand);
        // Сбрасываем сток для бренда
        $this->resetStockByBrand($brand);

        $category_links = $this->getCategoryLinks(self::URL_CATEGORY);

        $categories_sections = $this->getCategorySections($category_links);

        $product_links = $this->getProdLinks($categories_sections);

        foreach($product_links as $key => $product_link) {
            // if($product_link['name'] != "JUNKET (52)") continue;
            echo "----Get variations for {$product_link['name']} ({$product_link['href']})\n";
            $this->parseItem($product_link, $brand);
        }

        echo "\nSync items - {$this->count_items},  variations - {$this->count_variations}.\n";
    }

    /**
     * Возвращает ссылки на траницы всех моделей
     * @param  array $categories_sections ссылки на разделы категорий
     * @return array
     */
    private function getProdLinks($categories_sections) {
        $products_links = array();

        foreach($categories_sections as $key => $section) {
            $final_array = array();

            $link = $section['href'];
            $type = $section['type'];
            $name = $section['name'];

            // На этом этапе отсеиваем аксессуары, что бы не тратить время на парсинг
            if(strcasecmp($name, "ACCESSORIES") == 0) {
                echo "Пропускаем аксессуары !!! \n";
                continue;
            }

            // если это категория в которой сразу товары
            if(in_array($name, array("COLLECTIONS", "MENS", "WOMENS"))) {
                $section_product_links = $this->getPageItems($link, $type);
            } else {
                // это категория, которая содержит подкатегории и там уже товары
                // получаем подкатегории
                $special_section_products = $this->getPageItems($link, $type);

                // тут крутая конструкция, которая перебирая все подкатегории берет с них товары
                // и дописывает в массив, при этом каждый раз возвращая новый дополненный массив
                // соответственно последний элемент будет самый полный
                $section_product_links_result = array_map(function($product) use ($type, &$final_array) {
                    $final_array = array_merge($final_array, $this->getPageItems($product['href'], $type));

                    return $final_array;
                }, $special_section_products);

                // последний массив содержит все элементы
                $section_product_links = end($section_product_links_result);
            }

            $products_links = array_merge($products_links, $section_product_links);
        }

        return $products_links;
    }

    /**
     * Возвращает ссылки, имена и тип элементов с переданной страницы
     * @param  string $link
     * @param  string $type
     * @return array
     */
    private function getPageItems($link, $type) {
        $parent_link = self::URL_BASE;
        $items = array();

        $http = $this->getHttp();
        $http->doGet(self::URL_BASE .$link);
        $content = $http->getContents(false);

        $dom = str_get_html($content);

        $items_dom = $dom->find('.category');

        foreach($items_dom as $key => $item) {
            $item_span_dom = $item->find('a span');
            $item_name = trim($item_span_dom[0]->plaintext);

            $item_a_dom = $item->find('a');

            $item_link = trim($item_a_dom[0]->href);

            $items[] = [
                'name' => $item_name,
                'href' => $item_link,
                'type' => $type,
            ];
        }

        return $items;
    }

    /**
     * Возвращает ссылки на разделы категории
     * @param  array
     * @return array
     */
    private function getCategorySections($category_links) {
        $sections = array();

        foreach($category_links as $link_key => $category_link) {
            $link = $category_link['href'];
            $type = $category_link['type'];

            $sections = array_merge($sections, $this->getPageItems($link, $type));
        }

        return $sections;
    }

    /**
     * Возвращает ссылки на категории
     * @param  string ссылка на страницу категорий
     * @return array
     */
    private function getCategoryLinks($cat_href) {
        $http = $this->getHttp();
        $categories = array();

        $http->doGet($cat_href);
        $content = $http->getContents(false);

        $dom = str_get_html($content);

        $category_dom = $dom->find('.category');

        foreach($category_dom as $key => $cat) {
            $cat_a_dom = $cat->find('a.catAImg');
            $cat_link = $cat_a_dom[0]->href;

            $categories[] = [
                'type' => strrpos($cat, "SUNGLASSES") !== false ? 'sun' : 'eye',
                'href' => $cat_link,
            ];
        }

        return $categories;
    }

    /**
     * Парсинг страницы модели
     * @param  array        $product_link_arr массив с названием модели, ссылкой и типом
     * @param  CMSTableBrand $brand
     */
    private function parseItem($product_link_arr, CMSTableBrand $brand) {
        $variation_links_arr = array();
        $variations = array();
        $result = array();

        $item_name = $product_link_arr['name'];
        $item_url = $product_link_arr['href'];
        $item_type = $product_link_arr['type'];

        $variation_links_arr = $this->getVariationLinks($item_url);

        // не нашлось вариаций
        if(!$variation_links_arr) {
            return;
        }

        // определяем тип очков
        if($item_type === "eye") {
            $type = CMSLogicGlassesItemType::getInstance()->getEye();
        } else {
            $type = CMSLogicGlassesItemType::getInstance()->getSun();
        }


        $variations = $this->getVariations($variation_links_arr);

        // формируем массив обьектов
        foreach($variations as $key => $variation) {
            $external_id = $variation['item_code']." prov ".$this->getProviderId();

            $final_item_name = $item_name." ".$variation['item_code'];

            // небольшой лог
            echo "\n--------url          - " . $item_url."\n";
            echo "--------item title   - " . $final_item_name. "\n";
            echo "--------item ext id  - " . $external_id. "\n";
            echo "--------item code    - " . $variation['item_code']. "\n";
            echo "--------color code   - " . $variation['color_code']. "\n";
            echo "--------type         - " . $item_type. "\n";
            echo "--------item sizes   - " . $variation['size']."/0/0\n";
            echo "--------color title  - " . $variation['color']. "\n";
            echo "--------item image   - " . $variation['image']. "\n";
            echo "--------stock        - " . $variation['stock']. "\n";
            echo "--------price        - " . $variation['price'] . "\n\n";

            if(!$variation['stock']) {
                echo "--------Variation {$variation['color']} ({$variation['color_code']}) not in stock. (not parse!)\n";
                echo "==================================================================\n";
                continue;
            }

            if (!$variation['image']) {
                echo "--------Variation {$variation['color']} ({$variation['color_code']}) hasnt image.\n";
                // костыль
                // смотим есть ли эта деталь уже в базе и есть ли у нее изображение
                // если да то предполагаем что на сайте пропала картинка и синхронизируем
                $query = "SELECT `d`.`file_id`
                    FROM `amz_glasses_item` AS `i`
                    LEFT JOIN `amz_glasses_item_detail` AS `d` ON `d`.`item_id` = `i`.`item_id`
                    WHERE `i`.`item_ext_id` = :item_ext_id
                    AND `d`.`detail_color_code` = :detail_color_code";

                $q = CMSPluginDb::getInstance()->getQuery($query);
                $q->setText('item_ext_id', $external_id);
                $q->setText('detail_color_code', $variation['color_code']);

                $row = $q->execute()->getFirstRecord();

                if(!$row) {
                    echo "----------Variation hasnt image in base too.(not parse!)\n";
                    echo "==================================================================\n";
                    continue;
                }
            }

            $this->count_variations++;

            // это несколько иной метод получения картинки, который предпочтительнее для sftp
            $imgFile = CMSLogicGlassesFileCache::getInstance()->getOneMarchon($variation['image'], true, clone $this->getHttp());

            echo "==================================================================\n";

            $item = new CMSClassGlassesParserItem();

            $item->setTitle($final_item_name);
            $item->setBrand($brand);
            $item->setExternalId($external_id);
            $item->setImg($variation['image']);

            if($imgFile) {
                $item->setImgFile($imgFile->getFile());
            }

            $item->setType($type);
            $item->setColor($variation['color']);
            $item->setColorCode($variation['color_code']);
            $item->setStockCount(1);
            $item->setPrice($variation['price']);
            $item->setSize($variation['size']);
            $item->setSize2(0);
            $item->setSize3(0);
            $item->setIsValid(1);

            $result[] = $item;
        }

        if(empty($result)) {
            echo "\n--------No one variation to parse.\n\n";
            return;
        }

        $this->count_items++;

        foreach($result as $item) {
            if ($item instanceof CMSClassGlassesParserItem) {
                $item->sync();
                echo "ok\n";
            }
        }

    }

    /**
     * Возвращает информацию о вариациях модели
     * @param  array $variation_links_arr
     * @return array
     */
    private function getVariations($variation_links_arr) {
        $variations = array();
        $variation_param = array();

        $variations = array_map(function($variation_url) use(&$variation_param) {
            $variation_param = $this->parseVariationPage($variation_url);

            return $variation_param;
        }, $variation_links_arr);

        return $variations;
    }

    /**
     * Сбор информации со страницы вариации
     * @param  string $link
     * @return array
     */
    private function parseVariationPage($link) {
        $http = $this->getHttp();
        $color_code = "";
        $variation_color = "";
        $size_1 = "";

        $http->doGet(self::URL_BASE .$link);
        $content = $http->getContents(false);

        $dom = str_get_html($content);

        // достаем код продукта
        $variation_code_dom = $dom->find('.cat-prd-id p');
        $variation_code = trim($variation_code_dom[0]->plaintext);

        preg_match("/(.*)-(.*)/", $variation_code, $matches);
        $item_code = trim($matches[1]);
        $color_code = trim($matches[2]);

        $variation_color_dom = $dom->find('.cat-prd-dsc span');
        $variation_color_title = trim($variation_color_dom[0]->plaintext);

        preg_match("/\((\d+)\).*?([a-zA-z].*)/", $variation_color_title, $matches);
        $size_1 = isset($matches[1]) ? trim($matches[1]) : 0;
        $variation_color = isset($matches[2]) ? trim($matches[2]) : $variation_color_title;

        $variation_img_dom = $dom->find('.cat-prd-img img');

        if(!count($variation_img_dom)) {
            $variation_img = 0;
        } else {
            $variation_img = trim($variation_img_dom[0]->src);
        }

        $variation_price_dom = $dom->find('.cat-prd-prc');
        $variation_price = trim($variation_price_dom[0]->plaintext);

        $variation_stock_dom = $dom->find('#atpinformation');
        $variation_stock = trim($variation_stock_dom[0]->plaintext);

        // очищаем строку цены
        $variation_price = str_replace(array('&nbsp;', 'USD'), '', $variation_price);

        $variation_stock = stripos($variation_stock, "Available On") !== false ? 1 : 0;

        return array(
            'item_code' => $item_code,
            'color_code' => $color_code,
            'color' => $variation_color,
            'size' => $size_1,
            'image' => $variation_img,
            'price' => $variation_price,
            'stock' => $variation_stock,
        );
    }

    /**
     * Возвращает все ссылки на вариации со страницы модели
     * @param  string $item_url
     * @return array
     */
    private function getVariationLinks($item_url) {
        $variation_links = array();
        $http = $this->getHttp();

        // переходим на страницу продукта
        $http->doGet(self::URL_BASE .$item_url);

        $post = array(
            'pageselect' => 0,
            'page' => 1,
            'itemPageSize' => 0,
            'next' => 'setPageSize',
        );

        // отправляем пост для показа всех вариаций на одной странице
        $http->doPost("https://1242.ovault.com/oakb2b/catalog/updateItems/(layout=6_4_6_1&uiarea=1)/.do?areaid=workarea", $post);
        $content = $http->getContents(false);

        if(stripos($content, "No products found") !== false) {
            echo "------No variations found. \n";
            return;
        }

        $dom = str_get_html($content);

        $variations_a_dom = $dom->find('.cat-prd-data .cat-prd-dsc a');

        foreach($variations_a_dom as $key => $variation_a) {
            preg_match("/displayProdDetails\(\'(.*)\',/", $variation_a->onclick, $matches);
            $variation_links[] = trim($matches[1]);
        }

        return $variation_links;
    }
}