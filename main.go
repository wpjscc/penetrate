package main

import (
	"bufio"
	b64 "encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httputil"
	_ "net/http/pprof"
	"os"
	"reflect"
	"runtime"
	"strings"
	"sync"
	"time"

	"github.com/gobwas/ws"
	"github.com/gobwas/ws/wsutil"
	uuid "github.com/satori/go.uuid"
)

const maxRead = 25

type Object map[string]interface{}

func MemoryGetUsage(realUsage bool) uint64 {
	stat := new(runtime.MemStats)
	runtime.ReadMemStats(stat)
	return stat.Alloc
}

type RequestResponse struct {
	Request  *http.Request
	Response *http.ResponseWriter
}

var server_port = ":9502"
var vhost_http_port = ":9503"
var wsObjects = make(map[string]io.Writer)
var wsObjectConfigs = make(map[string]interface{})
var httpObjects = make(map[string]RequestResponse)
var httpToTunnelWs = make(map[string]string)
var tunnelWsToHttp = make(map[string]string)
var tunnelWsObjects = make(map[string]*net.Conn)
var myApp = MyApp{}
var myAppProxy = MyAppProxy{}

// var mutex = &sync.Mutex{}
var wgs = make(map[string]*sync.WaitGroup)
var dones = make(map[string]*chan bool)

var burstyLimiter = make(chan time.Time, 10)

func FirstUpper(s string) string {
	if s == "" {
		return ""
	}
	return strings.ToUpper(s[:1]) + s[1:]
}
func Invoke(any interface{}, name string, args ...interface{}) {
	inputs := make([]reflect.Value, len(args))
	for i, _ := range args {
		inputs[i] = reflect.ValueOf(args[i])
	}
	reflect.ValueOf(any).MethodByName(name).Call(inputs)
}
func ReflectStructMethod(Iface interface{}, MethodName string) error {
	ValueIface := reflect.ValueOf(Iface)

	// Check if the passed interface is a pointer
	if ValueIface.Type().Kind() != reflect.Ptr {
		// Create a new type of Iface, so we have a pointer to work with
		ValueIface = reflect.New(reflect.TypeOf(Iface))
	}

	// Get the method by name
	Method := ValueIface.MethodByName(MethodName)
	if !Method.IsValid() {
		return fmt.Errorf("Couldn't find method `%s` in interface `%s`, is it Exported?", MethodName, ValueIface.Type())
	}
	return nil
}
func ReflectStructField(Iface interface{}, FieldName string) error {
	ValueIface := reflect.ValueOf(Iface)

	// Check if the passed interface is a pointer
	if ValueIface.Type().Kind() != reflect.Ptr {
		// Create a new type of Iface's Type, so we have a pointer to work with
		ValueIface = reflect.New(reflect.TypeOf(Iface))
	}

	// 'dereference' with Elem() and get the field by name
	Field := ValueIface.Elem().FieldByName(FieldName)
	if !Field.IsValid() {
		return fmt.Errorf("Interface `%s` does not have the field `%s`", ValueIface.Type(), FieldName)
	}
	return nil
}

type receive_event_msg struct {
	Event string      `json:"event"`
	Data  interface{} `json:"data"`
}

type MyApp struct {
}

func (myApp *MyApp) setClientId(w io.Writer, op ws.OpCode, client_id string) {
	var msg = Object{
		"event": "system",
		"data": Object{
			"event": "setClientId",
			"data": Object{
				"client_id": client_id,
			},
		},
	}
	j, err1 := json.Marshal(msg)
	if err1 != nil {
		fmt.Println(err1)
	}
	fmt.Println("---------------MyApp-setClientId-test-----------------")
	fmt.Println(client_id)
	fmt.Println("---------------MyApp-setClientId-test-----------------")

	err := wsutil.WriteServerMessage(w, op, j)

	if err != nil {

		fmt.Println(err)

	}
}

func (myApp *MyApp) sendConfig(w io.Writer, op ws.OpCode, client_id string) {
	var msg = Object{
		"event": "system",
		"data": Object{
			"event": "sendConfig",
			"data": Object{
				"client_id": client_id,
			},
		},
	}
	j, err1 := json.Marshal(msg)
	if err1 != nil {
		fmt.Println(err1)
	}
	fmt.Println("---------------MyApp-sendConfig-test-----------------")
	fmt.Println(client_id)
	fmt.Println("---------------MyApp-sendConfig-test-----------------")

	err := wsutil.WriteServerMessage(w, op, j)

	if err != nil {

		fmt.Println(err)

	}
}

func (myApp *MyApp) onMessage(w io.Writer, op ws.OpCode, msg receive_event_msg) {
	event := FirstUpper(msg.Event)
	data := msg.Data
	fmt.Println("---------------MyApp-onMessage-1-----------------")
	if event != "" && data != nil {
		fmt.Println(event)

		err := ReflectStructMethod(myApp, event)

		if err != nil {
			fmt.Println(err)

		} else {
			fmt.Println("--------------MyApp-onMessage-2------------------")

			new_event := msg.Data.(map[string]interface{})["event"].(string)
			new_data := msg.Data.(map[string]interface{})["data"]
			fmt.Println(new_event)
			fmt.Println(new_data)
			fmt.Println("--------------MyApp-onMessage-2------------------")

			receive_event_msg := receive_event_msg{Event: new_event, Data: new_data}

			Invoke(myApp, event, w, op, receive_event_msg)
			fmt.Printf("Method `%s` found\n", event)
		}
	}
	fmt.Println("---------------MyApp-onMessage-1-----------------")

}

func (myApp *MyApp) Client(w io.Writer, op ws.OpCode, msg receive_event_msg) {
	event := FirstUpper(msg.Event)
	data := msg.Data
	fmt.Println("---------------MyApp-Client-1-----------------")

	if event != "" && data != nil {
		err := ReflectStructMethod(myApp, event)

		if err != nil {
			fmt.Println(err)

		} else {
			Invoke(myApp, event, w, op, data)
			fmt.Printf("Method `%s` found\n", event)
		}
	}
	fmt.Println("---------------MyApp-Client-1-----------------")

}

func (myApp *MyApp) GetStatus(w io.Writer, op ws.OpCode, msg map[string]interface{}) {
	fmt.Println("---------------MyApp-GetStatus-1-----------------")
	fmt.Println(msg)

	content := msg["content"].(string)
	fmt.Println(content)
	fmt.Println("content")
	fmt.Println("---------------MyApp-GetStatus-1-----------------")

	err := wsutil.WriteServerMessage(w, op, []byte(content))

	if err != nil {

		fmt.Println(err)

	}
}
func (myApp *MyApp) ReceiveConfig(w io.Writer, op ws.OpCode, msg map[string]interface{}) {
	fmt.Println("---------------MyApp-ReceiveConfig-1-----------------")
	client_id := msg["client_id"].(string)
	configs := msg["configs"].([]interface{})
	wsObjectConfigs[client_id] = configs
	fmt.Println(msg)
	fmt.Println("---------------MyApp-ReceiveConfig-1-----------------")

}

func (myApp *MyApp) createProxy(w io.Writer, request_id string, local_ip string, local_port float64, host string) {
	fmt.Println("---------------MyApp-createProxy-1-----------------")
	u2 := uuid.Must(uuid.NewV4()).String()

	for {
		if _, ok := tunnelWsObjects[u2]; !ok {
			break
		} else {
			fmt.Println(tunnelWsObjects, u2)
			fmt.Println("createProxy----------------error")

			u2 = uuid.Must(uuid.NewV4()).String()
		}
	}
	fmt.Printf("UUIDv4: %s\n", u2)
	proxy_client_id := u2
	//todo 事件
	var msg = Object{
		"event": "system",
		"data": Object{
			"event": "createProxy",
			"data": Object{
				"request_id":      request_id,
				"local_ip":        local_ip,
				"local_port":      local_port,
				"proxy_client_id": proxy_client_id,
				"uniqid":          proxy_client_id,
				"host":            host,
			},
		},
	}
	j, err1 := json.Marshal(msg)
	if err1 != nil {
		fmt.Println(err1)
	}

	err := wsutil.WriteServerMessage(w, ws.OpText, j)

	if err != nil {

		fmt.Println(err)

	}
	fmt.Println("---------------MyApp-createProxy-1-----------------")

}

type MyAppProxy struct {
}

func (myAppProxy *MyAppProxy) onMessage(w *net.Conn, op ws.OpCode, msg receive_event_msg) {
	fmt.Println("---------------myAppProxy-onMessage-1-----------------")
	event := FirstUpper(msg.Event)
	data := msg.Data
	if event != "" && data != nil {
		err := ReflectStructMethod(myAppProxy, event)
		if err != nil {
			fmt.Println(err)
		} else {
			fmt.Println("--------------myAppProxy-onMessage-2------------------")
			new_event := msg.Data.(map[string]interface{})["event"].(string)
			new_data := msg.Data.(map[string]interface{})["data"]
			fmt.Println("--------------myAppProxy-onMessage-2------------------")

			receive_event_msg := receive_event_msg{Event: new_event, Data: new_data}

			Invoke(myAppProxy, event, w, op, receive_event_msg)
			fmt.Printf("Method `%s` found\n", event)
		}
	}
	fmt.Println("---------------myAppProxy-onMessage-1-----------------")

}
func (myAppProxy *MyAppProxy) Client(w *net.Conn, op ws.OpCode, msg receive_event_msg) {
	fmt.Println("---------------MyAppProxy-Client-1-----------------")

	event := FirstUpper(msg.Event)
	data := msg.Data

	if event != "" && data != nil {
		err := ReflectStructMethod(myAppProxy, event)

		if err != nil {
			fmt.Println(err)

		} else {
			Invoke(myAppProxy, event, w, op, data)
			fmt.Printf("Method `%s` found\n", event)
		}
	}
	fmt.Println("---------------MyAppProxy-Client-1-----------------")

}
func (myAppProxy *MyAppProxy) GetStatus(w *net.Conn, op ws.OpCode, msg map[string]interface{}) {
	fmt.Println("---------------MyAppProxy-GetStatus-1-----------------")
	fmt.Println(msg)

	content := msg["content"].(string)
	fmt.Println(content)
	fmt.Println("content")

	fmt.Println("---------------MyAppProxy-GetStatus-1-----------------")

}
func (myAppProxy *MyAppProxy) ProxyRequest(w *net.Conn, op ws.OpCode, msg map[string]interface{}) {
	fmt.Println("---------------MyAppProxy-ProxyRequest-1-----------------")
	// fmt.Println(msg)

	proxyClientId := msg["proxy_client_id"].(string)
	uniqid := msg["uniqid"].(string)
	request_id := msg["request_id"].(string)
	local_ip := msg["local_ip"].(string)
	local_port := msg["local_port"].(float64)
	host := msg["host"].(string)

	httpToTunnelWs[request_id] = proxyClientId //uniqid
	tunnelWsToHttp[proxyClientId] = request_id //uniqid
	tunnelWsObjects[uniqid] = w

	http := httpObjects[request_id]

	request := http.Request

	requestDump, err := httputil.DumpRequest(request, true)
	if err != nil {
		fmt.Println(err)
	}
	requestString := string(requestDump)
	var new_msg = Object{
		"event": "system",
		"data": Object{
			"event": "receiveRequest",
			"data": Object{
				"content":         b64.StdEncoding.EncodeToString(([]byte(requestString))),
				"local_ip":        local_ip,
				"local_port":      local_port,
				"proxy_client_id": proxyClientId,
				"uniqid":          proxyClientId,
				"host":            host,
			},
		},
	}
	j, err1 := json.Marshal(new_msg)
	if err1 != nil {
		fmt.Println(err1)
	}
	err2 := wsutil.WriteServerMessage(*w, ws.OpText, j)
	if err2 != nil {
		fmt.Println(err2)
	}

	fmt.Println("---------------MyAppProxy-ProxyRequest-1-----------------")

}
func (myAppProxy *MyAppProxy) ProxyReponse(w *net.Conn, op ws.OpCode, msg map[string]interface{}) error {

	fmt.Println("---------------MyAppProxy-ProxyResponse-1-----------------")
	uniqid := msg["uniqid"].(string)
	content := msg["content"].(string)
	var request_id string
	if _, ok := tunnelWsToHttp[uniqid]; !ok {

		//todo error
		fmt.Println("ProxyReponse-------uniqid-----error", uniqid)
		return nil
	}

	request_id = tunnelWsToHttp[uniqid]
	// defer wgs[request_id].Done()

	if _, ok := httpObjects[request_id]; !ok {
		//todo error
		fmt.Println("ProxyReponse------request_id------error", request_id)
		return nil
	}

	fmt.Println(uniqid)
	decode_content, _ := b64.StdEncoding.DecodeString(content)
	fmt.Println("ProxyReponse----------22")

	reader := bufio.NewReader(strings.NewReader(string(decode_content)))

	message := string(decode_content)

	messages := Explode("\r\n\r\n", message)

	// fmt.Println(messages)
	// headerMessage := messages[0]
	bodyMessage := messages[1]
	// headerMessages := Explode("\r\n", headerMessage)

	// headerFirstLine := headerMessages[0]
	// headerFirstLines := Explode(" ", headerFirstLine)
	// statusCodeStr := headerFirstLines[1]

	// fmt.Println("statusCodeStr-------", statusCodeStr)

	// var statusCode int
	// if statusCode, err := strconv.Atoi(statusCodeStr); err == nil {
	// 	// fmt.Printf("i=%d, type: %T\n", i, i)
	// 	statusCode = statusCode
	// }

	// statusCode := strconv.Atoi(statusCodeStr)

	response := *httpObjects[request_id].Response
	request := httpObjects[request_id].Request
	new_response, err4 := http.ReadResponse(reader, request)

	fmt.Println(new_response.StatusCode)
	fmt.Println("ProxyReponse----------33")

	if err4 != nil {
		fmt.Println(*new_response)
	}
	headers := new_response.Header
	fmt.Println("ProxyReponse----------44")

	for key, header := range headers {
		for _, value := range header {
			fmt.Println("ProxyReponseHeader----------", key, value)

			response.Header().Add(key, value)
		}
	}

	if new_response.StatusCode == 302 {
		fmt.Println("ProxyReponseHeader----------")
		// panic("ProxyRep")
	}
	fmt.Println("StatusCode---------")
	fmt.Println(new_response.StatusCode)
	fmt.Println("StatusCode---------")
	fmt.Println("headers---------")
	fmt.Println(headers)
	fmt.Println("headers---------")
	fmt.Println("ProxyReponse----------55")

	// defer new_response.Body.Close()
	// b, err := httputil.DumpResponse(new_response, true)
	// if err != nil {
	// 	log.Fatalln(err)
	// }
	// b, err := ioutil.ReadAll(new_response.Body)
	// if err != nil {
	// 	log.Fatalln(err)
	// }
	fmt.Println("ProxyReponse----------66")

	if bodyMessage != "" {
		response.Write([]byte(bodyMessage))
	}
	response.WriteHeader(new_response.StatusCode)

	fmt.Println("ProxyReponse----------77")
	//在请求结尾处删除
	// delete(httpObjects, request_id)
	// delete(tunnelWsObjects, uniqid)
	// delete(tunnelWsToHttp, uniqid)
	// delete(httpToTunnelWs, request_id)

	fmt.Println("---------------MyAppProxy-ProxyResponse-1-----------------")
	// wgs[request_id].Done()

	*dones[request_id] <- true
	return nil

}

func Explode(delimiter, text string) []string {
	if len(delimiter) > len(text) {
		return strings.Split(delimiter, text)
	} else {
		return strings.Split(text, delimiter)
	}
}

func GetDataByInterface(data interface{}) interface{} {
	switch key := data.(type) {
	case map[string]interface{}:
		return data.(map[string]interface{})
	case string:
		return key
	case int:
		return key

	case int64:
		return key
	default:
		return data.([]interface{})
	}
}

func In_array(needle interface{}, hystack interface{}) bool {

	switch key := needle.(type) {
	case string:

		for _, item := range hystack.([]string) {
			if key == item {
				return true
			}
		}
		fmt.Println(7777)

	case int:
		for _, item := range hystack.([]int) {
			if key == item {
				return true
			}
		}
	case int64:
		for _, item := range hystack.([]int64) {
			if key == item {
				return true
			}
		}
	default:
		return false
	}
	return false
}

type Hander struct {
	http.Handler
}

func (h *Hander) ServeHTTP(w http.ResponseWriter, r *http.Request) {

	<-burstyLimiter

	u1 := uuid.Must(uuid.NewV4()).String()

	for {
		if _, ok := httpObjects[u1]; !ok {

			break
		} else {
			fmt.Println(httpObjects, u1)
			fmt.Println("ServeHTTP----------------error")
			// panic("error")
			u1 = uuid.Must(uuid.NewV4()).String()
		}
	}
	fmt.Printf("request_id: %s\n", u1)
	fmt.Printf("request_path: %s\n", r.URL.Path)
	// var wg sync.WaitGroup
	done := make(chan bool, 1)
	dones[u1] = &done
	go func() {

		httpObjects[u1] = RequestResponse{Request: r, Response: &w}

		host := string(r.Host)
		fmt.Println(strings.Split(host, ":"))
		host = strings.Split(host, ":")[0]
		state := false
		for key, wsObject := range wsObjects {
			if state {
				break
			}
			if wsObjectConfigs[key] != nil {

				configs := wsObjectConfigs[key]
				switch configs.(type) {
				case []interface{}:
					for _, config := range configs.([]interface{}) {
						switch config.(type) {
						case map[string]interface{}:
							tcp_type := config.(map[string]interface{})["type"].(string)
							local_ip := config.(map[string]interface{})["local_ip"].(string)
							local_port := config.(map[string]interface{})["local_port"].(float64)
							custom_domains := config.(map[string]interface{})["custom_domains"]
							if state {
								break
							}
							switch custom_domains.(type) {
							case map[string]interface{}: //关联数组
								break
							default: //索引数组
								if custom_domains != nil {
									aaaaa := custom_domains.([]interface{})
									new_custom_domains := make([]string, len(aaaaa))
									for i, new_custom_domain := range aaaaa {
										bbbb := new_custom_domain.(string)
										new_custom_domains[i] = bbbb
									}
									// fmt.Println("tcp_type")
									// fmt.Println(tcp_type)
									// fmt.Println(config)
									// fmt.Println(host)
									// fmt.Println(new_custom_domains)
									if local_ip != "" && local_port > 0 && In_array(host, new_custom_domains) && tcp_type == "http" {
										myApp.createProxy(wsObject, u1, local_ip, local_port, host)
										state = true
										break
									}
								}

							}
						}

					}
					fmt.Println(999999)

				case map[string]interface{}:
					fmt.Println(configs)
					fmt.Println(888888)

				default:
					fmt.Println(configs)
					fmt.Println(100000)

				}
			}

		}
	}()

	// // close(*dones[u1])

	select {
	case <-*dones[u1]:
		fmt.Println("success")

	case <-time.After(10 * time.Second): //超时
		if http, ok := httpObjects[u1]; ok {
			response := *http.Response
			fmt.Fprint(response, "超时4041")
		}
		fmt.Println("timeout----------------------- 10")
	}

	// wgs[u1].Wait()

	if uniqid, ok := httpToTunnelWs[u1]; ok {
		delete(tunnelWsToHttp, uniqid)
		delete(tunnelWsObjects, uniqid)
	}

	// if http, ok := httpObjects[u1]; ok {
	// 	response := *http.Response
	// 	fmt.Fprint(response, "4041")
	// }

	delete(httpObjects, u1)
	delete(httpToTunnelWs, u1)
	delete(dones, u1)
	// wgs[u1].Wait()
	// delete(wgs, u1)
	// requestDump, err := httputil.DumpRequest(r, true)
	// if err != nil {
	// 	fmt.Println(err)
	// }

}

func main() {

	go func() {
		for t := range time.Tick(1 * time.Millisecond) {
			burstyLimiter <- t
		}
	}()
	ticker := time.NewTicker(1000 * time.Millisecond)
	go func() {
		for {
			select {
			case t := <-ticker.C:
				fmt.Println("Tick at", t)
				// fmt.Println("wsObjects:len:", len(wsObjects))
				// fmt.Println("wsObjectConfigs:len:", len(wsObjectConfigs))
				// fmt.Println("httpObjects:len:", len(httpObjects))
				// fmt.Println("tunnelWsObjects:len:", len(tunnelWsObjects))
				// fmt.Println("tunnelWsToHttp:len:", len(tunnelWsToHttp))
				// fmt.Println("httpToTunnelWs:len:", len(httpToTunnelWs))
				// memoryGetUsage := MemoryGetUsage(true)
				// f := float64(memoryGetUsage / 1024)
				// fmt.Printf("memoryGetUsage is %f\n", f)
				// fmt.Println("wgs:len:", len(wgs))
				// fmt.Println("dones:len:", len(dones))
				// debug.FreeOSMemory()
			}
		}
	}()
	go func() {
		http.HandleFunc("/websocket", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			conn, _, _, err := ws.UpgradeHTTP(r, w)
			if err != nil {
				// handle error
			}
			u1 := uuid.Must(uuid.NewV4()).String()
			fmt.Printf("UUIDv4: %s\n", u1)
			wsObjects[u1] = conn
			myApp.setClientId(conn, ws.OpText, u1)
			myApp.sendConfig(conn, ws.OpText, u1)
			go func() {
				defer func() {
					conn.Close()
				}()

				for {
					msg, op, err := wsutil.ReadClientData(conn)
					if err != nil {
						// handle error
						if op == 0x01 {

						} else {
							delete(wsObjects, u1)
							delete(wsObjectConfigs, u1)
							// panic(err)
							break
						}
					}

					message := receive_event_msg{}
					if err := json.Unmarshal(msg, &message); err != nil {
						fmt.Println(err)
					} else {
						myApp.onMessage(conn, op, message)
					}
				}
			}()
		}))
		http.HandleFunc("/tunnel", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			conn, _, _, err := ws.UpgradeHTTP(r, w)
			if err != nil {
				// handle error
			}
			//todo error iowait
			go func() {
				defer conn.Close()

				for {
					msg, op, err := wsutil.ReadClientData(conn) //todo error iowait
					if (op == ws.OpClose) && true {
						fmt.Println(2222222222222)
						fmt.Println(&conn)
						fmt.Println(tunnelWsObjects)
						fmt.Println(333333333333)
						for key, v := range tunnelWsObjects {
							fmt.Println(key)
							fmt.Println(v)

							if v == &conn {
								// panic(msg)
								fmt.Println("tunnel", 11111)

								delete(tunnelWsObjects, key)
								fmt.Println("tunnel", 22222)
								fmt.Println("tunnel", key)

								if request_id, ok := tunnelWsToHttp[key]; ok {
									fmt.Println("tunnel", 333333)

									if http, ok := httpObjects[request_id]; ok {
										response := *http.Response
										fmt.Fprint(response, "4042")
									}
									fmt.Println("tunnel", 4444444)

									// if wg1, ok := wgs[request_id]; ok {
									// fmt.Println("tunnel", 555555)
									// fmt.Println(wg1)
									// wg1.Done()
									// delete(wgs, request_id)
									// }
									if done, ok := dones[request_id]; ok {
										fmt.Println("tunnel", 555555)
										fmt.Println(done)
										*done <- true
										// close(*dones[request_id])
										delete(dones, request_id)
									}
									fmt.Println("tunnel", 66666)

									delete(httpObjects, request_id)
									delete(httpToTunnelWs, request_id)
									fmt.Println("tunnel", 77777)

								}
								delete(tunnelWsToHttp, key)
								fmt.Println("tunnel", 8888)

							}
						}
						return
					}
					if err != nil {
						// handle error
						if op == ws.OpText {

						} else if op == ws.OpClose {

							// delete(tunnelWsObjects, u1)
							// panic(err)
							// break
							return
						} else {
							return

						}
					}

					message := receive_event_msg{}
					if err := json.Unmarshal(msg, &message); err != nil {
						fmt.Println(err)
						fmt.Println(msg, op)
					} else {
						myAppProxy.onMessage(&conn, op, message)
					}
				}
			}()

		}))

		http.ListenAndServe(server_port, nil)
	}()

	go func() {

		hander := &Hander{}
		err := http.ListenAndServe(vhost_http_port, hander) // 设置监听的端口

		if err != nil {
			log.Fatal("ListenAndServe: ", err)
		}
	}()

	//todo tcp proxy
	go func() {
		// hostAndPort := fmt.Sprintf("%s:%s", flag.Arg(0), flag.Arg(1))
		hostAndPort := fmt.Sprintf("%s:%s", "0.0.0.0", 9504)

		listener := initServer(hostAndPort)
		for {
			conn, err := listener.Accept()
			checkError(err, "Accept: ")
			go connectionHandler(conn)
		}
	}()

	select {}

}

func connectionHandler(conn net.Conn) {
	connFrom := conn.RemoteAddr().String()
	println("Connection from: ", connFrom)
	// sayHello(conn)
	for {
		var ibuf []byte = make([]byte, maxRead+1)
		length, err := conn.Read(ibuf[0:maxRead])
		ibuf[maxRead] = 0 // to prevent overflow
		switch err {
		case nil:
			handleMsg(length, err, ibuf)
		case os.EAGAIN: // try again
			continue
		default:
			goto DISCONNECT
		}
	}
DISCONNECT:
	err := conn.Close()
	println("Closed connection: ", connFrom)
	checkError(err, "Close: ")
}
func initServer(hostAndPort string) *net.TCPListener {
	serverAddr, err := net.ResolveTCPAddr("tcp", hostAndPort)
	checkError(err, "Resolving address:port failed: '"+hostAndPort+"'")
	listener, err := net.ListenTCP("tcp", serverAddr)
	checkError(err, "ListenTCP: ")
	println("Listening to: ", listener.Addr().String())
	return listener
}
func handleMsg(length int, err error, msg []byte) {
	if length > 0 {
		print("<", length, ":")
		for i := 0; ; i++ {
			if msg[i] == 0 {
				break
			}
			fmt.Printf("%c", msg[i])
		}
		print(">")
	}
}
func checkError(error error, info string) {
	if error != nil {
		panic("ERROR: " + info + " " + error.Error()) // terminate program
	}
}
