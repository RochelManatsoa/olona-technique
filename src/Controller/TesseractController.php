<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

class TesseractController extends AbstractController
{
    
    #[Route('/api/tesseract', name: 'tesseract', methods:['POST'])]
    public function index(Request $request, LoggerInterface $logger): Response
    {
        $logger->info('Tesseract API called');

        $file = $request->files->get('pdf');
        if (!$file) {
            $logger->error('No file uploaded');
            return new Response('No file uploaded', Response::HTTP_BAD_REQUEST);
        }
        
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads';
        $pdfPath = $uploadDir . '/' . uniqid() . '.pdf';

        try {
            $file->move($uploadDir, $pdfPath);
            $logger->info('File uploaded successfully', ['path' => $pdfPath]);
            $uploadedFiles = scandir($uploadDir);
            $logger->info('Files in upload directory after upload', ['files' => $uploadedFiles]);
        } catch (FileException $e) {
            $logger->error('Failed to upload file', ['error' => $e->getMessage()]);
            return new Response('Failed to upload file', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $outputDir = $uploadDir . '/' . uniqid();
        mkdir($outputDir);
        $logger->info('Created output directory', ['directory' => $outputDir]);

        // Utilisez le chemin complet vers pdftoppm
        $pdftoppmCmd = "/usr/bin/pdftoppm";

        // Convert PDF to images
        $cmd = "$pdftoppmCmd -png $pdfPath $outputDir/image";
        exec($cmd, $output, $retval);
        $logger->info('Convert PDF to images command executed', ['command' => $cmd, 'output' => $output, 'retval' => $retval]);

        // Log the files found
        $images = glob("$outputDir/*.png");
        $logger->info('Files found in output directory', ['files' => $images]);

        if ($retval !== 0) {
            $logger->error('Error converting PDF to images', ['output' => $output]);
            return new Response('Failed to convert PDF to images', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Process each image with Tesseract
        $textOutput = [];
        foreach ($images as $image) {
            $output = null;
            $retval = null;
            $cmd = "tesseract $image stdout";
            exec($cmd, $output, $retval);
            $logger->info('Tesseract command executed', ['command' => $cmd, 'output' => $output, 'retval' => $retval]);

            if ($retval !== 0) {
                $logger->error('Error processing image with Tesseract', ['image' => $image, 'output' => $output]);
                continue;
            }
            if (!empty($output)) {
                $logger->info('Tesseract output', ['output' => $output]);
                $textOutput[] = implode("\n", $output);
            } else {
                $logger->info('Tesseract output is empty for image', ['image' => $image]);
            }
        }

        // Clean up
        unlink($pdfPath);
        array_map('unlink', glob("$outputDir/*.*"));
        rmdir($outputDir);

        $logger->info('Tesseract processing completed');

        return new Response(implode("\n", $textOutput));
    }
}
