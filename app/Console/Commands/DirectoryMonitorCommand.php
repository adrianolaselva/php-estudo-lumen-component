<?php

namespace App\Console\Commands;

use App\Business\CPayIntencaoVenda;
use App\Business\CPayVender;
use App\Helpers\CPayFileHelper;
use App\Models\File;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Integracao\ControlPay;

/**
 * Class DirectoryMonitorCommand
 * @package App\Console\Commands
 */
class DirectoryMonitorCommand extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'controlpay-service:start {minutes?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inicializa worker para monitorar diretório com arquivos para acionar o tef';

    /**
     * @var array
     */
    private $cPayclient;

    /**
     * DirectoryMonitorCommand constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $files = Storage::disk(env('STORAGE_CONFIG'))->files(CPayFileHelper::PATH_CONFIG);

        foreach ($files as $file)
        {
            $params = parse_ini_string(Storage::disk(env('STORAGE_CONFIG'))->get($file));
            $this->cPayclient[basename($file)] = new ControlPay\Client($params);
        }
    }

    /**
     *
     */
    public function handle()
    {

        $arguments = $this->arguments();

        $minutes = $arguments['minutes'];

        if(empty($minutes))
            $minutes = 60;

        /**
         * Adaptação para rodar command a cada 2 segundos
         */
        $inverval = 2;
        for($i = 0; $i < ceil($minutes/$inverval); $i++)
        {
            $this->readFiles();
            sleep($inverval);
        }
    }

    /**
     * Efetua leitura do diretório
     */
    private function readFiles()
    {
        $files = Storage::disk(env('STORAGE_CONFIG'))->files(CPayFileHelper::PATH_REQ);

        foreach ($files as $file)
        {
            if(strpos($file, '.wrk') !== false)
                continue;

            $file = CPayFileHelper::fileToWork($file);

            if(!Storage::disk(env('STORAGE_CONFIG'))->has($file))
                continue;

            $this->process($file);
        }
    }

    /**
     * @param $file
     */
    private function process($file)
    {
        try{
            $data = CPayFileHelper::loadFileContent($file);

            $requireParams = [
                'identificador',
                'api',
                'referencia'
            ];

            foreach ($requireParams as $param)
                if(!in_array($param, $requireParams))
                    throw new \Exception("Parâmetro '$param' não informado no arquivo!!");

            if(!empty(File::where('name', basename($file))->first()))
                throw new \Exception(sprintf("Arquivo nome %s já utilizada",
                    str_replace('.wrk', '', basename($file))));

            if($data['api'] == CPayVender::API_VENDA_VENDER)
                if(!empty(File::where('reference', $data['referencia'])->first()))
                    throw new \Exception(sprintf("Referência %s já utilizada",
                        str_replace('.wrk', '', $data['referencia'])));

            Log::info(sprintf("Processando arquivo %s", basename($file)));

            $fileModel = File::create([
                'identifier' => $data['identificador'],
                'api' => $data['api'],
                'reference' => $data['referencia'],
                'name' => basename($file),
                'content' => json_encode($data, JSON_PRETTY_PRINT),
                'created_at' => Carbon::now()
            ]);

            $responseContent = null;
            switch (strtolower($data['api']))
            {
                case CPayVender::API_VENDA_VENDER:
                    $responseContent = (new CPayVender($this->cPayclient[$data['identificador']], $fileModel))
                        ->vender($data);
                    break;
                case CPayIntencaoVenda::API_INTENCAO_VENDA_GET_BY_ID:
                    $responseContent = (new CPayIntencaoVenda($this->cPayclient[$data['identificador']], $fileModel))
                        ->carregar($data);
                    break;
                case CPayVender::API_VENDA_CANCELAR:
                    $responseContent = (new CPayVender($this->cPayclient[$data['identificador']], $fileModel))
                        ->cancelarVenda($data);
                    break;
                case CPayIntencaoVenda::API_INTENCAO_VENDA_GET_BY_FILTROS:
                    $responseContent = (new CPayIntencaoVenda($this->cPayclient[$data['identificador']], $fileModel))
                        ->getByFiltros($data);
                    break;
                default:
                    throw new \Exception("Método {$data['api']} não implementado");
            }

            CPayFileHelper::fileProccessed($file, $responseContent);
        }catch (\Exception $ex){
            Log::info($ex->getMessage());
            CPayFileHelper::fileProccessedError($ex, $file);
        }
    }

//    /**
//     * @param $file
//     * @param $responseContent
//     */
//    private function fileProccessed($file, $responseContent)
//    {
//        try{
//            $responseStatus = sprintf("response.status=%s%s", 0, PHP_EOL);
//            $responseStatus .= sprintf("response.message=%s%s", "Dados processados com sucesso", PHP_EOL);
//
//            Storage::disk(env('STORAGE_CONFIG'))->put(
//                sprintf("%s/%s", CPayFileHelper::PATH_RESP, basename($file)),
//                $responseStatus . $responseContent
//            );
//
//            Storage::disk(env('STORAGE_CONFIG'))->move(
//                $file,
//                sprintf("%s/%s_%s", CPayFileHelper::PATH_PROCCESSED, date('Y-m-d_His'), basename($file))
//            );
//        }catch (\Exception $ex){
//            Log::error(sprintf('Falha ao mover arquivo [%s/%s] => [%s]', CPayFileHelper::PATH_REQ, basename($file), $ex->getMessage()));
//        }
//    }
//
//    /**
//     * @param \Exception $ex
//     * @param $file
//     */
//    private function fileProccessedError(\Exception $ex, $file)
//    {
//        try{
//            $resposeContent = sprintf("response.status=%s%s", -1, PHP_EOL);
//            $resposeContent .= sprintf("response.message=%s%s", $ex->getMessage(), PHP_EOL);
//
//            Storage::disk(env('STORAGE_CONFIG'))->put(
//                sprintf("%s/%s", CPayFileHelper::PATH_RESP, basename($file)),
//                $resposeContent
//            );
//
//            Storage::disk(env('STORAGE_CONFIG'))->move(
//                $file,
//                sprintf("%s/%s_%s", CPayFileHelper::PATH_ERROR, date('Y-m-d_His'),basename($file))
//            );
//
//        }catch (\Exception $ex){
//            Log::error(sprintf('Falha ao mover arquivo [%s/%s] => [%s]', CPayFileHelper::PATH_REQ, basename($file), $ex->getMessage()));
//        }
//    }
//
//    /**
//     * @param $file
//     * @return array
//     */
//    private function loadFileContent($file)
//    {
//        $data = [];
//
//        try{
//
//            $data = CPayFileHelper::convertFileContentToArray(
//                sprintf("%s/%s", Storage::disk(env('STORAGE_CONFIG'))->getAdapter()->getPathPrefix(), CPayFileHelper::PATH_REQ),
//                basename($file)
//            );
//
//        }catch (\Exception $ex){
//            Log::error(sprintf('Falha ao processar arquivo %s/%s => [%s]', CPayFileHelper::PATH_REQ, basename($file)), $ex->getMessage());
//        }
//
//        return $data;
//    }

}