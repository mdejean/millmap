// function prototype for NYCgeo
#ifdef WIN32
#include "NYCgeo.h"
#else
#include "geo.h"
#define _GNU_SOURCE
#include <dlfcn.h>
#include <linux/limits.h>
#endif
// header file of Work Area layouts
#include "pac.h"

#include <stdio.h>
#include <string.h>
#include <stdlib.h>

 #define min(a,b) \
   ({ __typeof__ (a) _a = (a); \
       __typeof__ (b) _b = (b); \
     _a < _b ? _a : _b; })
 #define max(a,b) \
   ({ __typeof__ (a) _a = (a); \
       __typeof__ (b) _b = (b); \
     _a > _b ? _a : _b; })
   
#ifndef WIN32
#define NYCgeo geo
#endif

struct coord {
    char x[7];
    char y[7];
};

struct coord intersection_coord(char node[7]) {
    C_WA1 wa1 = {
        .input = {
            .func_code = {'2', ' '},
            .platform_ind = 'P',
            .node = {node[0], node[1], node[2], node[3], node[4], node[5], node[6]} 
        }
    };
    C_WA2_F2 wa2 = {};
    
    NYCgeo((char*)&wa1, (char*)&wa2);
    
    struct coord ret;
    
    memcpy(ret.x, wa2.coord[0], 7);
    memcpy(ret.y, wa2.coord[1], 7);
    
    return ret;
}

int main(int argc, char** argv) {
    C_WA1 wa1 = {};
    C_WA2_F3S wa2 = {};
    
    wa1.input.platform_ind = 'P';
    
    if (argc < 3) {
        puts("streetstretch borough_code on_street [from_street to_street [from_direction [to_direction]]]");
        return 1;
    } else if (argc == 4) {
        puts("must supply both from_street and to_street");
        return 1;
    }
    
#ifndef WIN32
    Dl_info d = {};
    if (dladdr(geo, &d)) {
        char* last_sep;
        last_sep = strrchr(d.dli_fname, '/');
        if (last_sep) {
            last_sep = (char*)memrchr(d.dli_fname, '/', last_sep - d.dli_fname);
            if (last_sep && last_sep - d.dli_fname + 6 < PATH_MAX) {
                char p[PATH_MAX] = {};
                memcpy(p, d.dli_fname, last_sep - d.dli_fname);
                strcpy(&p[last_sep - d.dli_fname], "/fls/");
                setenv("GEOFILES", p, 0);
            }
        }
    }
#endif
    
    wa1.input.func_code[0] = '3';
    wa1.input.func_code[1] = 'S';
    
    char boro = argv[1][0];
    
    wa1.input.sti[0].boro = boro;
    memcpy(wa1.input.sti[0].Street_name, 
           argv[2], 
           min(sizeof(wa1.input.sti[0].Street_name), strlen(argv[2])) );
    if (argc > 3) {
        wa1.input.sti[1].boro = boro;
        memcpy(wa1.input.sti[1].Street_name, 
               argv[3], 
               min(sizeof(wa1.input.sti[1].Street_name), strlen(argv[3])) );
        
        wa1.input.sti[2].boro = boro;
        memcpy(wa1.input.sti[2].Street_name, 
               argv[4], 
               min(sizeof(wa1.input.sti[2].Street_name), strlen(argv[4])) );
        
        if (argc > 5) {
            wa1.input.comp_direction = argv[5][0];
        }
        if (argc > 6) {
            wa1.input.comp_direction2 = argv[6][0];
        }
    }
    
    NYCgeo((char*)&wa1, (char*)&wa2);
    
    if ((memcmp(wa1.output.ret_code, "00", 2) == 0) ||
        (memcmp(wa1.output.ret_code, "01", 2) == 0)) {
        size_t cross_strs_len 
            = (wa2.nbr_x_str[0] - '0') * 100 
            + (wa2.nbr_x_str[1] - '0') * 10 
            + (wa2.nbr_x_str[2] - '0');

        printf("[");
        for (int i = 0; i < cross_strs_len; i++) {
            
            printf("\"%.7s\"", wa2.cross_strs[i].node_nbr);
            if (i != cross_strs_len - 1) {
                printf(",");
            }
        }
        printf("]");
    } else {
         //~ printf("{\"error_code\": \"%c%c\", \"error_message\": \"%.80s\"}",
            //~ wa1.output.ret_code[1], wa1.output.ret_code[0],
            //~ wa1.output.msg);
    }
    
    return 0;
}
