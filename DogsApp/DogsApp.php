<?php



//(( READABLE ))
interface Readable
{
    public function getData($source): array;
}

class CsvDogReader implements Readable
{

    public static $config = [
        'fOpenMode' => 'r',
        'delimiter' => ';',
        'lineLength' => 0,
    ];
    private $dogAttributes;

    private function addAttributeNames(array $dogRawProps): array
    {
        $propsWithNames = [];
        foreach ($this->dogAttributes as $iAttr => $attribute)
        {
            $propsWithNames[$attribute['attrName']] = $dogRawProps[$iAttr];
        }

        return $propsWithNames;
    }

    public function __construct(array $dogAttributes)
    {
        $this->dogAttributes = $dogAttributes;
    }

    public function getData($source): array
    {
        $file = fopen($source, self::$config['fOpenMode']);

        $rawDogs = [];
        $delimiter = self::$config['delimiter'];
        $length = self::$config['lineLength'];

        while (($dataRow = fgetcsv($file, $length, $delimiter)) !== false)
        {
            $rawDogs[] = $this->addAttributeNames($dataRow);
        }

        fclose($file);
        return $rawDogs;
    }

}

//(( STORABLE ))
interface DogsStorable
{
    public function fill(array $dogs):void;

    public function findDog(string $dogId):array;

    public function findDogs(array $userDogProps): array;
}

//(( DogsStoreSQL ))
class DogsStoreSQL implements DogsStorable
{
    private $dogsTableName;

    /** @var \PDO $dbLink */
    private $dbLink;
    private $attributes;
    private $dbConfig;
    private $namesOfRequiredAttrs;

    private function generateDogId(array $dogRawProps):string
    {
        $namesOfRequiredAttrs = $this->namesOfRequiredAttrs ? $this->namesOfRequiredAttrs : $this->getNamesOfRequiredAttrs();

        $strOfRawProps = '';

        foreach ($dogRawProps as $propName => $propValue)
        {
            if (in_array($propName, $namesOfRequiredAttrs))
            {
                $strOfRawProps .= $dogRawProps[$propName];
            }
        }

        return hash('md5', $strOfRawProps);
    }

    private function getNamesOfRequiredAttrs():array
    {
        $namesOfRequiredAttrs = [];

        foreach ($this->attributes as $attribute)
        {
            if ($attribute['required'])
            {
                $namesOfRequiredAttrs[] = $attribute['attrName'];
            }
        }

        $this->namesOfRequiredAttrs = $namesOfRequiredAttrs;
        return $namesOfRequiredAttrs;
    }

    public function __construct(array $attributes, array $dbConfig)
    {
        $this->dbConfig = $dbConfig;
        $this->attributes = $attributes;
        $this->dogsTableName = $dbConfig['tableName'];
    }

    private function prepareDogs($rawDogs):array
    {
        $rawDogsWithId = $rawDogs;

        foreach ($rawDogsWithId as &$dogRawProps)
        {
            $dogRawProps = ['dogId' => $this->generateDogId($dogRawProps)] + $dogRawProps;
        }

        return $rawDogsWithId;
    }

    private function prepareColumnNames():array
    {
        $columnNames = array_map(function ($attribute) {
            return $attribute['attrName'];
        },
                $this->attributes
        );
        array_unshift($columnNames, 'dogId');
        return $columnNames;
    }

    private function createSqlInsert(string $tableName, array $columnNames):string
    {
        $sqlPart1 = "INSERT INTO `$tableName` ";

        $columnNamesSQLPart = array_map(function ($columnName) {
            return "`$columnName`";
        }, $columnNames);

        $sqlPart2 = "(" . implode(", ", $columnNamesSQLPart) . ")";
        $sqlPart3 = " VALUES ";

        $bindValuesSQLPart = array_map(function($columnName) {
            return ":$columnName";
        }, $columnNames);

        $sqlPart4 = "(" . implode(",", $bindValuesSQLPart) . ")";
        $sqlPart5 = " ON DUPLICATE KEY UPDATE ";

        $columnsSqlModified = array_map(function ($columnName) {
            return "`$columnName` = :$columnName";
        }, $columnNames);

        $sqlPart6 = implode(", ", $columnsSqlModified) . ";";

        return $sqlPart1 . $sqlPart2 . $sqlPart3 . $sqlPart4 . $sqlPart5 . $sqlPart6;
    }
    
    private function connect(): PDO
    {
        list (
                'user' => $user,
                'password' => $password,
                'dbName' => $dbName,
                'host' => $host
                ) = $this->dbConfig;

        $options = [
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\''
        ];

        if ($this->dbLink)
        {
            return $this->dbLink;
        }

        try
        {

            $this->dbLink = new PDO(
                    "mysql:host=$host;dbname=$dbName",
                    $user,
                    $password,
                    $options
            );
            
            return $this->dbLink;
            
        } catch (Throwable $err)
        {
            print_r($err->getMessage());
        }
    }
    
    private function createDogsTableIfNotExist(): bool
    {
        $this->connect();
        $dbName = $this->dogsTableName;
        $attributes = $this->attributes;
        $columnType = "TEXT";

        $sqlPart1 = "CREATE TABLE IF NOT EXISTS `{$dbName}` ";
        $sqlPart2 = " ( `dogId` VARCHAR(32) NOT NULL, ";
        $sqlPart3 = "";
        foreach ($attributes as $oneAttribute)
        {
            $strAttrType = "`{$oneAttribute['attrName']}` {$columnType}, ";
            $sqlPart3 = $sqlPart3 . $strAttrType;
        }
        $sqlPart4 = " PRIMARY KEY (`dogId`));";

        $sql = $sqlPart1 . $sqlPart2 . $sqlPart3 . $sqlPart4;
        return $this->dbLink->exec($sql);
    }

    public function fill(array $rawDogs): void
    {
        $preparedColumnNames = $this->prepareColumnNames();
        $preparedRawDogs = $this->prepareDogs($rawDogs);

        $sql = $this->createSqlInsert(
                $this->dogsTableName,
                $preparedColumnNames,
        );

        $this->createDogsTableIfNotExist();
        $stmt = $this->dbLink->prepare($sql);

        foreach ($preparedRawDogs as $dogProp)
        {
            $stmt->execute($dogProp);
        }
    }
    
    public function findDog(string $dogId):array
    {
        $sql = "SELECT * from " . $this->dogsTableName . " WHERE dogId = :dogId";
        $this->connect();
        $stmt = $this->dbLink->prepare($sql);
        $stmt->execute(['dogId' => $dogId]);
        return $stmt->fetch(PDO::FETCH_ASSOC); // *** если не нашли собаку
    }

    public function findDogs(array $userRawDogProps): array
    {
        $preparedSql = [];
        foreach ($userRawDogProps as $dogPropName => $dogProp)
        {
            $preparedSql[] = "`$dogPropName` = :$dogPropName";
        }
        $preparedSql = implode(" AND ", $preparedSql);

        $this->connect();
        
        $sql = "SELECT * from `".$this->dogsTableName."` WHERE $preparedSql;";
        $stmt = $this->dbLink->prepare($sql);
        $stmt->execute($userRawDogProps);
        
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result; // *** если не нашли собак
    }
}

//(( > CONTROLLER ))
class DogController
{
    public $appConfig;
    
    private $dbConfig;
    private $dogAttributes;
    private $dataSource;
    private $reader;
    private $dogStore;
    private $classValidator;
    private $classViewer;
    
    function __construct(array $appConfig)
    {
        $this->appConfig = $appConfig;
        
        $this->dbConfig = $appConfig['db'];
        $this->dogAttributes = $appConfig['dogAttributes'];
        $this->dataSource = $appConfig['reader']['source'];
        
        $classReader = $appConfig['reader']['class'];
        $this->reader = new $classReader($this->dogAttributes);
        
        $classStore = $appConfig['store']['class'];
        $this->dogStore = new $classStore($this->dogAttributes, $this->dbConfig);

        
        $this->classValidator = $appConfig['validator']['class'];
        $this->classViewer = $appConfig['viewer']['class'];
    }

    public function handleData():array
    {
        try
        {
            $rawDogs = $this->reader->getData($this->dataSource);
            return $this->addDogs($rawDogs);
        } catch (Throwable $err)
        {
            echo $err->getMessage();
        }

    }
    
    public function addDog(array $dogRawProps):array
    {
        return $this->addDogs([$dogRawProps]);
        
    }
    
    public function addDogs(array $rawDogs):array
    {
        $validRawDogs = array_unique(
                $this->classValidator::validateRawDogs($rawDogs, $this->dogAttributes),
                SORT_REGULAR
        );
        
        $hadledDogs = ['validDogs'=>$validRawDogs,'invalidDogs'=>$this->classValidator::$errorDogs];

        $this->dogStore->fill($hadledDogs['validDogs']);
        
        return $hadledDogs['invalidDogs'];
    }
    
    public function showInfoDog(string $dogId):void
    {
        $dogRawProps = $this->dogStore->findDog($dogId);
        $this->classViewer::renderDogsProfile([$dogRawProps]);
    }
    
    public function showInfoDogs(array $userRawDogProps):void
    {
        $rawDogs = $this->dogStore->findDogs($userRawDogProps);
        $this->classViewer::renderDogsProfile($rawDogs);
    }

}

//(( VALIDATOR ))
class RawDogsValidator
{
    public static $errorDogs = [];

    public static function validateRawDogs(array $rawDogs, array $dogAttributes): array
    {
        $validRawDogs = [];

        self::clearErrorDogs();
        foreach ($rawDogs as $oneRawDog)
        {
            if (self::validateOneRawDog($oneRawDog, $dogAttributes))
            {
                $validRawDogs[] = $oneRawDog;
            }
        }

        return $validRawDogs;
    }

    private static function validateOneRawDog(
            array $dogRawProps,
            array $dogAttributes
    ): bool
    {
        foreach ($dogAttributes as $iAttr => $attribute)
        {
            if (
                    $attribute['required'] &&
                    trim($dogRawProps[$attribute['attrName']]) === ''
            )
            {
                self::$errorDogs[] = $dogRawProps;
                return false;
            }
        }
        return true;
   }

    private static function clearErrorDogs():void
    {
        self::$errorDogs = [];
    }

}

class DogsViewer
{
    public static function renderDogsProfile(array $dogs = [])
    {
        if(count($dogs) === 0)
        {
            echo "<br>" . __FILE__ . " --- " . __LINE__ . "<pre>";
            print_r('Такие собаки отсутсвуют в базе');
            echo "</pre><br>";
            return;
        }
        echo '<br> Начало отчета для отображения <br>';
        foreach($dogs as $indexDog => $dogRawProps)
        {
            echo "<br>Отчет по собаке ".++$indexDog.":<br>";
            self::renderProfileOneDog($dogRawProps);
        }
        echo '<br> Конец отчета для отображения <br>';
    }

    private static function renderProfileOneDog(array $dogRawProps):void
    {
        echo "<br>" . __FILE__ . " --- " . __LINE__ . "<pre>";
        print_r($dogRawProps);
        echo "</pre><br>";
    }
}

//(( CLIENT CODE ))

/*Для тестирования приложения достаточно указать в конфигурации настройки бд, 
 * с учетом того что приложение работает с mysql.
 */

//Конфигурация приложения
$appConfig = [
    'db' => [
        'host' => 'localhost',
        'dbName' => 'dogstest',
        'user' => 'root',
        'password' => '',
        'tableName' => 'dogsTable'
    ],
    'reader' => [
        'source' => './DogsData.csv',
        'class' => 'CsvDogReader',
    ],
    'store' => [
        'class' => 'DogsStoreSQL',
    ],
    'validator' => [
        'class' => 'RawDogsValidator'
    ],
    'viewer' => [
        'class' => 'DogsViewer',
    ],
    'dogAttributes' => [
        ['attrName' => 'name', 'required' => true],
        ['attrName' => 'birthDate', 'required' => true],
        ['attrName' => 'owner', 'required' => false],
        ['attrName' => 'breed', 'required' => true],
        ['attrName' => 'image', 'required' => false],
        ['attrName' => 'color', 'required' => true]
    ]
];

$dogController = new DogController($appConfig);

/*Загружаем данные из файла csv, наименование файла описано в конфигурации ['reader']['source']
handleData() возвращает собак с незаполенными обязательными полями заданными в конфигурации*/
$invalidDogs = $dogController->handleData();
//
//echo "<br>" . __FILE__ . " --- " . __LINE__ . "<pre>";
//print_r($invalidDogs);
//echo "</pre><br>";

/*Выводим профиль собаки по конкретному dogId собаки взятому из бд*/
//$dogController->showInfoDog('029875d650094e9e882c5473fe1e4db5');

/*Выводим данные по собакам с владельцем с именем Carlos и желтым цветом*/
//$dogController->showInfoDogs(['owner'=>'Carlos','color'=>'broun']);

/*Есть возможность просто добавлять собак с учетом обязятельных полей заданных в конфигурации*/
//$dog = [
//    'name'=>'Bobik',
//    'birthDate'=>'2008',
//    'owner'=>'Vasiliy Petrovich',
//    'breed'=>'Layka',
//    'image'=>'',
//    'color'=>'white'
//    ];
//$dogController->addDog($dog);




