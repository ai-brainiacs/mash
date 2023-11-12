<?php

namespace Rpurinton\Ash;

class OpenAI
{
    private $client = null;
    public $models = [];
    private $model = null;
    private $maxTokens = null;
    private $basePrompt = null;
    private $baseTokens = 0;
    public $runningProcess = null;
    private $util = null;
    public $history = null;
    public $functionHandlers = [];
    public $modelPicker = null;

    public function __construct(private $ash)
    {
        $this->util = new Util();
        $this->history = new History($this->util, $this->ash);
        $this->client = \OpenAI::client($this->ash->config->config['openaiApiKey']);
        $models = $this->client->models()->list()->data;
        foreach ($models as $model) if (mb_substr($model->id, 0, 3) == 'gpt') $this->models[] = $model->id;
        $this->modelPicker = new ModelPicker($this);
        $this->modelPicker->selectModel();
        $this->modelPicker->selectMaxTokens();
        if (!$ash->debug) passthru("clear");
        $this->basePrompt = file_get_contents(__DIR__ . "/base_prompt.txt");
        $this->baseTokens = $this->util->tokenCount($this->basePrompt);
        $this->welcomeMessage();
    }

    public function __destruct()
    {
        if ($this->runningProcess) proc_terminate($this->runningProcess);
    }

    public function welcomeMessage()
    {
        $this->history->saveMessage(["role" => "system", "content" => "User started a new ash session from : " . $this->ash->sysInfo->sysInfo["who-u"] . "\n Please greet them!"]);
        $messages = $this->buildPrompt();
        /*
        $messages[] = ["role" => "system", "content" => "Write a welcome or login banner for SSH that can contain several helpful elements for users when they log in. You might include the following information:

            System name and purpose - A brief identifier of the server, such as Welcome to the Acme Corporation's production server.
            Contact information - In case of issues, provide a point of contact, like an email address or phone number.
            Terms of use - A short statement like By logging in, you agree to the terms of use.
            System status or load - If you have a script to display this, you could include CPU, RAM usage, or uptime to inform the user about the current server state.
            Security reminders - Like Remember: Don't share your credentials or leave sessions unattended.
            Motivational or humorous quote - Sometimes a small, light-hearted quote can set a positive tone for the session.
            Last login information - To remind users of their last session, useful for security.
            Maintenance schedules or updates - Any upcoming dates when users should expect downtime or when updates are scheduled.
            It's essential to keep it concise to prevent overwhelming the user upon each login."];
        */
        $this->handlePromptAndResponse($messages);
    }

    public function userMessage($input)
    {
        $this->history->saveMessage(["role" => "user", "content" => $input]);
        $this->handlePromptAndResponse($this->buildPrompt());
    }

    private function buildPrompt()
    {
        $messages[] = ["role" => "system", "content" => $this->basePrompt];
        $dynamic_prompt = "Your full name is " . $this->ash->sysInfo->sysInfo['hostFQDN'] . ", but people can call you " . $this->ash->sysInfo->sysInfo['hostName'] . " for short.\n";
        $dynamic_prompt .= "Here is the current situation: " . print_r($this->ash->sysInfo->sysInfo, true);
        if ($this->ash->config->config['emojiSupport']) $dynamic_prompt .= "Emoji support enabled!  Use it to express yourself!  🤣🤣🤣\n";
        else $dynamic_prompt .= "Emoji support disabled. Do not send emoji!\n";
        if ($this->ash->config->config['colorSupport']) $dynamic_prompt .= "Use copious amounts of escape codes! Feel free to use them gratuitously in the future to add more style and emphasis to all your outputs including combinations of colors, bold, underline, and italics!\n";
        else $dynamic_prompt .= "Terminal color support disabled. Do not send color codes!\n";
        $messages[] = ["role" => "system", "content" => $dynamic_prompt];
        $dynamic_tokens = $this->util->tokenCount($dynamic_prompt);
        $response_space = round($this->maxTokens * 0.1, 0);
        $history_space = $this->maxTokens - $this->baseTokens - $dynamic_tokens - $response_space;
        $messages = array_merge($messages, $this->history->getHistory($history_space));
        return $messages;
    }

    private function handlePromptAndResponse($messages)
    {
        $prompt = [
            "model" => $this->model,
            "messages" => $messages,
            "temperature" => 0.1,
            "top_p" => 0.1,
            "frequency_penalty" => 0.0,
            "presence_penalty" => 0.0,
            "functions" => $this->getFunctions(),
        ];
        if ($this->ash->debug) echo ("debug: Sending prompt to OpenAI: " . print_r($prompt, true) . "\n");
        if (!$this->ash->config->config["emojiSupport"]) echo ("Thinking...");
        else echo ("🧠 Thinking...");

        try {
            $stream = $this->client->chat()->createStreamed($prompt);
        } catch (\Exception $e) {
            if ($this->ash->debug) echo ("debug: Error: " . print_r($e, true) . "\n");
            else echo ("Error: " . $e->getMessage() . "\n");
            return;
        } catch (\Error $e) {
            if ($this->ash->debug) echo ("debug: Error: " . print_r($e, true) . "\n");
            else echo ("Error: " . $e->getMessage() . "\n");
            return;
        } catch (\Throwable $e) {
            if ($this->ash->debug) echo ("debug: Error: " . print_r($e, true) . "\n");
            else echo ("Error: " . $e->getMessage() . "\n");
            return;
        }
        $this->handleStream($stream);
    }

    private function handleStream($stream)
    {
        echo ("\r                                                                           \r");
        $function_call = null;
        $full_response = "";
        $line = "";
        $status_ptr = 0;
        $status_chars = ["|", "/", "-", "\\"];
        foreach ($stream as $response) {
            $reply = $response->choices[0]->toArray();
            $finish_reason = $reply["finish_reason"];
            if (isset($reply["delta"]["function_call"]["name"])) {
                $function_call = $reply["delta"]["function_call"]["name"];
                $functionNameDisplay = str_replace("_", " ", $function_call);
                echo ("\r✅ Running $functionNameDisplay... %");
            }
            if ($function_call) {
                if (isset($reply["delta"]["function_call"]["arguments"])) {
                    $status_ptr++;
                    if ($status_ptr > 3) $status_ptr = 0;
                    echo ("\r✅ Running $functionNameDisplay... " . $status_chars[$status_ptr]);
                    $full_response .= $reply["delta"]["function_call"]["arguments"];
                }
            } else if (isset($reply["delta"]["content"])) {
                $delta_content = $reply["delta"]["content"];
                $full_response .= $delta_content;
                $line .= $delta_content;
                $line_break_pos = mb_strrpos($line, "\n");
                if ($line_break_pos !== false) {
                    $output = mb_substr($line, 0, $line_break_pos);
                    $line = mb_substr($line, $line_break_pos + 1);
                    $output = str_replace("\n", "\n", $output);
                    $output = str_replace("\\e", "\e", $output);
                    $output = $this->markdownToEscapeCodes($output);
                    echo ("$output\n");
                } else {
                    if (mb_strlen($line) > $this->ash->sysInfo->sysInfo['terminalColumns']) {
                        $wrapped_text = wordwrap($line, $this->ash->sysInfo->sysInfo['terminalColumns'], "\n", true);
                        $line_break_pos = mb_strrpos($wrapped_text, "\n");
                        $output = mb_substr($wrapped_text, 0, $line_break_pos);
                        $line = mb_substr($wrapped_text, $line_break_pos + 1);
                        $output = str_replace("\n", "\n", $output);
                        $output = str_replace("\\e", "\e", $output);
                        $output = $this->markdownToEscapeCodes($output);
                        echo ("$output\n");
                    }
                }
            }
        }

        if ($function_call) {
            $arguments = json_decode($full_response, true);
            $this->handleFunctionCall($function_call, $arguments);
        } else {
            if ($line != "") {
                $output = wordwrap($line, $this->ash->sysInfo->sysInfo['terminalColumns'], "\n", true);
                $output = str_replace("\n", "\n", $output);
                $output = str_replace("\\e", "\e", $output);
                $output = $this->markdownToEscapeCodes($output);
                echo trim($output) . "\n";
            }
            $assistant_message = ["role" => "assistant", "content" => $full_response];
            $this->history->saveMessage($assistant_message);
        }
        if ($this->ash->debug) {
            if ($function_call) echo ("✅ Response complete.  Function call: " . print_r($arguments, true) . "\n");
            else echo ("Response complete.\n");
        }
    }

    private function handleFunctionCall($function_call, $arguments)
    {
        if ($this->ash->debug) echo ("debug: handleFunctionCall($function_call, " . print_r($arguments, true) . ")\n");
        $function_message = ["role" => "assistant", "content" => null, "function_call" => ["name" => $function_call, "arguments" => json_encode($arguments)]];
        $this->history->saveMessage($function_message);
        if (isset($this->functionHandlers[$function_call])) {
            $handler = $this->functionHandlers[$function_call];
            $result = $handler($arguments);
            if ($this->ash->debug) echo ("debug: handleFunctionCall($function_call, " . print_r($arguments, true) . ") result: " . print_r($result, true) . "\n");
            $this->functionFollowUp($function_call, $result);
            return;
        } else $this->functionFollowUp($function_call, ["stdout" => "", "stderr" => "Error (ash): function handler for $function_call not found.  Does ash/src/functions.d/$function_call.php exist?", "exitCode" => -1]);
        return;
    }

    private function functionFollowUp($function_call, $result)
    {
        $function_result = ["role" => "function", "name" => $function_call, "content" => json_encode($result)];
        $this->history->saveMessage($function_result);
        $this->handlePromptAndResponse($this->buildPrompt());
    }

    private function markdownToEscapeCodes($text)
    {
        $text = str_replace("\\e", "\e", $text);
        $text = str_replace("```", "", $text);
        if ($this->ash->config->config['colorSupport']) {
            // look for text wrapped in **xxx**
            $text = preg_replace("/\*\*(.*?)\*\*/", "\e[1m$1\e[0m", $text);
            // look for text wrapped in *xxx*
            $text = preg_replace("/\*(.*?)\*/", "\e[3m$1\e[0m", $text);
            // look for text wrapped in _xxx_
            $text = preg_replace("/\_(.*?)\_/", "\e[3m$1\e[0m", $text);
            // look for text wrapped in ~xxx~
            $text = preg_replace("/\~(.*?)\~/", "\e[9m$1\e[0m", $text);
            // look for text wrapped in `xxx`
            $text = preg_replace("/\`(.*?)\`/", "\e[7m$1\e[0m", $text);
            return $text;
        } else {
            // strip out markdown characters
            $text = preg_replace("/\*\*(.*?)\*\*/", "$1", $text);
            $text = preg_replace("/\*(.*?)\*/", "$1", $text);
            $text = preg_replace("/\_(.*?)\_/", "$1", $text);
            $text = preg_replace("/\~(.*?)\~/", "$1", $text);
            $text = preg_replace("/\`(.*?)\`/", "$1", $text);
            return $text;
        }
    }

    private function getFunctions()
    {
        exec('ls ' . __DIR__ . '/functions.d/*.json', $functions);
        $result = [];
        foreach ($functions as $function) {
            $result[] = json_decode(file_get_contents($function), true);
            $handlerFile = str_replace(".json", ".php", $function);
            if (file_exists($handlerFile)) {
                include($handlerFile);
            }
        }
        return $result;
    }
}
