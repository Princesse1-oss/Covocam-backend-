<?php
namespace App\Command;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Crée un utilisateur de test',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Vérifier si l'utilisateur existe déjà
        $existing = $this->entityManager->getRepository(Utilisateur::class)
            ->findOneBy(['email' => 'admin@covocam.cm']);
        
        if ($existing) {
            $io->warning('Cet utilisateur existe déjà !');
            return Command::SUCCESS;
        }

        // Créer l'admin
        $admin = new Utilisateur();
        $admin->setNom('Admin');
        $admin->setPrenom('CovoCam');
        $admin->setEmail('admin@covocam.cm');
        $admin->setTelephone('699000000');
        $admin->setTypeUtilisateur('admin');
        $admin->setEstActif(true);
        $admin->setRoles(['ROLE_ADMIN']);

        $hashedPassword = $this->passwordHasher->hashPassword($admin, 'Admin1234!');
        $admin->setMotDePasse($hashedPassword);

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        $io->success('Utilisateur créé avec succès !');
        $io->table(
            ['Email', 'Mot de passe', 'Rôle'],
            [['admin@covocam.cm', 'Admin1234!', 'ROLE_ADMIN']]
        );

        return Command::SUCCESS;
    }
}