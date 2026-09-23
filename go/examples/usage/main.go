package main

import (
	"fmt"
	"log"

	kid "github.com/keboola/keboola-identifier/go"
)

func main() {
	value, err := kid.New("kbc-eu-central-1", map[string]string{
		kid.EntityProject: "9876", kid.EntityConfig: "my config",
	})
	if err != nil {
		log.Fatal(err)
	}
	fmt.Println(value)
	project, _ := value.Project()
	config, _ := value.Get(kid.EntityConfig)
	fmt.Println(project)
	fmt.Println(config)
	parsed, err := kid.Parse(value.String())
	if err != nil {
		log.Fatal(err)
	}
	fmt.Println(parsed)
}
