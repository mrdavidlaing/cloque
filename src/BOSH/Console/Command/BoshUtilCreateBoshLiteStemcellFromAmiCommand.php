<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Aws\Ec2\Ec2Client;
use Symfony\Component\Yaml\Yaml;

class BoshUtilCreateBoshLiteStemcellFromAmiCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('boshutil:create-bosh-lite-stemcell-from-ami')
            ->setDescription('Create a bosh-lite stemcell from the given AMI')
            ->addArgument(
                'stemcell-url',
                InputArgument::REQUIRED,
                'Upstream stemcell URL'
            )
            ->addArgument(
                'source-ami',
                InputArgument::REQUIRED,
                'Source AMI'
            )
            ->addOption(
                's3-prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'S3 Path Prefix for new stemcell'
            )
            ->addOption(
                's3-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Override S3 file name'
            )
            ->addOption(
                'stemcell-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Override stemcell name'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));

        $sourceRegion = $network['regions'][$input->getOption('director')]['region'];


        chdir(sys_get_temp_dir());
        $uid = uniqid('stemcell-');
        exec('mkdir -p ' . escapeshellarg($uid) . '/stemcell');
        chdir($uid);

        $output->isDebug()
            && $output->writeln('cwd: ' . getcwd());


        $output->isVeryVerbose()
            && $output->writeln('fetching stemcell');

        unset($stdout);
        exec('wget -qO- ' . escapeshellarg($input->getArgument('stemcell-url')) . ' | tar -xzf- -C stemcell', $stdout, $exit);

        if ($exit) {
            $output->writeln('<error>' . implode("\n", $stdout) . '</error>');
            
            return $exit;
        }

        $output->isVerbose()
            && $output->writeln('fetched stemcell');


        $output->isVeryVerbose()
            && $output->writeln('patching manifest');

        $manifest = Yaml::parse(file_get_contents('stemcell/stemcell.MF'));

        $manifest['cloud_properties']['ami'] = [
            $sourceRegion => $input->getArgument('source-ami'),
        ];

        if (null !== $input->getOption('stemcell-name')) {
            $manifest['name'] = $input->getOption('stemcell-name');
            $manifest['cloud_properties']['name'] = $input->getOption('stemcell-name');
        }

        file_put_contents('stemcell/stemcell.MF', Yaml::dump($manifest));

        unset($stdout);
        exec('cd stemcell && tar -czf ../stemcell.tgz * && cd .. && rm -fr stemcell', $stdout, $exit);

        if ($exit) {
            $output->writeln('<error>' . implode("\n", $stdout) . '</error>');

            return $exit;
        }


        $output->isVeryVerbose()
            && $output->writeln('uploading to s3');

        $s3Bucket = $network['root']['bucket'];
        $s3Key = sprintf(
            'bosh-stemcell/aws/%s/%s',
            $sourceRegion,
            $input->getOption('s3-name') ?: basename($input->getArgument('stemcell-url'))
        );
        $s3Url = 'https://' . $s3Bucket . '.s3.amazonaws.com/' . $s3Key;

        $awsS3 = \Aws\S3\S3Client::factory();
        $awsS3->putObject([
            'Bucket' => $s3Bucket,
            'SourceFile' => 'stemcell.tgz',
            'ACL' => 'public-read',
            'Key' => $s3Key,
        ]);

        exec('rm -f stemcell.tgz');

        $output->writeln('Uploaded Stemcell: <info>' . $s3Url . '</info>');


        $output->isVeryVerbose()
            && $output->writeln('uploading to director');

        $this->execCommand(
            $input,
            $output,
            'boshdirector:stemcells:put',
            [
                'stemcell' => $s3Url,
            ]
        );

        $output->isVerbose()
            && $output->writeln('uploaded to director');
    }
}
