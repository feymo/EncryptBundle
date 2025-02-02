<?php

namespace SpecShaper\EncryptBundle\Command;

use Doctrine\Migrations\Query\Query;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Command\Command;
use SpecShaper\EncryptBundle\Exception\EncryptException;
use SpecShaper\EncryptBundle\Encryptors\EncryptorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Common\Annotations\Reader;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'encrypt:database',
    description: 'Encrypts or Decrypts the database'
)]
class EncryptDatabaseCommand extends Command
{

    private ?EntityManagerInterface $em;
    
    private array $encryptedFields = [];

    private $plannedSql = [];

    public function __construct(
        private readonly Reader $annotationReader,
        private readonly EncryptorInterface $encryptor,
        private readonly ManagerRegistry $registry,
        private array $annotationArray
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('direction', InputArgument::REQUIRED,'Encrypt or Decrypt db.');
        $this->addOption('manager', null,InputOption::VALUE_OPTIONAL,'Nominate the database connection manager name.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $io = new SymfonyStyle($input, $output);

        $direction = $input->getArgument('direction');
        $managerName = $this->registry->getDefaultManagerName();

        $managerNameOption = $input->getOption('manager');

        if(!empty($managerNameOption)) {
            $managerName = $managerNameOption;
        }

        $this->em = $this->registry->getManager($managerName);
        
        $this->getEncryptedFields();

        $io->title('Decrypting the database');

        $tables = count($this->encryptedFields);

        $io->writeln($tables . ' tables to decrypt');

        $io->progressStart($tables);

        foreach ($this->encryptedFields as $entityName => $fieldArray){
            $this->decryptTable($entityName, $fieldArray, $direction);
            $io->progressAdvance();
        }

        $io->progressFinish($tables);

        return Command::SUCCESS;
    }

    private function decryptTable(string $tableName, array $fieldArray, string $direction){

        $statement = '';

        // Get all the field names that have been encrypted as an array.
        $select = array_keys($fieldArray);

        // Convert those to comma seperated string like lastname, firstname.
        $select = implode(', ', $select);
        $selectQuery = sprintf('SELECT id, %s FROM %s', $select, $tableName);

        // Fetch these encrypted rows
        $encryptedEntityFields = $this->em->getConnection()->fetchAllAssociative($selectQuery);

        foreach ($encryptedEntityFields as $entity) {

            $sql = 'UPDATE ' . $tableName . ' SET ';

            $decryptedFields = [];
            foreach($fieldArray as $fieldName => $refProperty){

                if('encrypt' === $direction) {
                    $newValue = $this->encryptor->encrypt($entity[$fieldName]);
                } else {
                    $newValue = $this->encryptor->decrypt($entity[$fieldName]);
                }

                $decryptedFields[] = $fieldName . ' = "' . $newValue . '"';
            }

            $sql .= implode( ', ', $decryptedFields);

            $sql .= ' WHERE id = ' . $entity['id'] . ';';

            $statement .= $sql;

        }

        $this->em->getConnection()->executeStatement($statement);
    }

    private function getEncryptedFields(): array
    {

        /** @var ClassMetadata[] $meta */
        $meta = $this->em->getMetadataFactory()->getAllMetadata();

        // Loop through entities
        foreach ($meta as $entityMeta) {

            if($entityMeta->isMappedSuperclass){
                continue;
            }

            $tableName = $entityMeta->getTableName();


            if (isset($this->encryptedFields[$tableName])) {
                return $this->encryptedFields[$tableName];
            }

            $meta = $this->em->getClassMetadata($entityMeta->getName());

            foreach ($meta->getReflectionProperties() as $key => $refProperty) {
                if ($this->isEncryptedProperty($refProperty)) {
                    $columnName = $meta->getColumnName($key);
                    $this->encryptedFields[$tableName][$columnName] = $refProperty;
                }
            }
        }

        return $this->encryptedFields;
    }

    private function isEncryptedProperty(\ReflectionProperty $refProperty)
    {

        foreach ($refProperty->getAttributes() as $refAttribute) {
            if (in_array($refAttribute->getName(), $this->annotationArray)) {
                return true;
            }
        }

        foreach ($this->annotationReader->getPropertyAnnotations($refProperty) as $key => $annotation) {
            if (in_array(get_class($annotation), $this->annotationArray)) {
                $refProperty->setAccessible(true);

                $this->logger->debug(sprintf('Use of @Encrypted property from SpecShaper/EncryptBundle in property %s is deprectated.
                    Please use #[Encrypted] attribute instead.',
                    $refProperty
                ));

                return true;
            }
        }

        return false;
    }

    protected function addSql(
        string $sql,
        array $params = [],
        array $types = []
    ): void {
        $this->plannedSql[] = new Query($sql, $params, $types);
    }
}
